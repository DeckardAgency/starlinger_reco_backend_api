<?php

namespace App\Service;

use ApiPlatform\Metadata\Exception\ExceptionInterface as ApiPlatformException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use App\Entity\Address;
use App\Entity\Client;
use App\Entity\DeliveryType;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PaymentType;
use App\Entity\Product;
use App\Entity\User;
use App\Message\OrderCreatedMessage;
use App\Security\ClientAgentAuthorization;
use App\State\Processor\OrderPriceProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * `POST /api/v1/orders/checkout`: turns one (possibly mixed-client) cart into
 * ONE order per client, all-or-nothing.
 *
 * A client agent's cart may hold lines for several managed clients, each to be
 * shipped to that client's own address. The payload groups the lines per client:
 *
 *   { isDraft, customerReference, notes, paymentType, deliveryType,
 *     groups: [ { onBehalfOfClient: IRI|null (null = own company),
 *                 shippingAddressId, billingAddressId|null,
 *                 items: [ { product: IRI, quantity } ] } ] }
 *
 * Each group becomes an Order whose order- and item-level onBehalfOfClient is
 * the group's client, and which runs through OrderPriceProcessor exactly like a
 * single `POST /orders`: user binding, agent authorisation, pricing, tax,
 * purchase-limit debit, persistence. The batch runs in ONE DB transaction, so a
 * failing group (limit exceeded, foreign address, not an authorised agent, …)
 * rolls back every order and every debit and the caller gets that group's error.
 * OrderCreatedMessages are dispatched only after the commit (the processor is
 * told to defer them). All orders of one call share a `checkoutReference`.
 */
final class OrderCheckoutService
{
    /** Serialization groups of each order in the response (POST /orders response + line details). */
    public const NORMALIZATION_GROUPS = ['order:read', 'order:item', 'order_item:read'];

    private const REFERENCE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly IriConverterInterface $iriConverter,
        private readonly ClientAgentAuthorization $agentAuth,
        private readonly OrderPriceProcessor $orderPriceProcessor,
        private readonly ValidatorInterface $validator,
        private readonly NormalizerInterface $normalizer,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload the decoded request body
     *
     * @return array{checkoutReference: string, orders: list<array<string, mixed>>}
     *
     * @throws BadRequestHttpException   invalid payload, foreign address, duplicate client, limit exceeded
     * @throws AccessDeniedHttpException caller may not act on behalf of a group's client
     */
    public function checkout(array $payload): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $isDraft = $this->readBool($payload, 'isDraft');
        $customerReference = $this->readNullableString($payload, 'customerReference');
        $notes = $this->readNullableString($payload, 'notes');
        $paymentType = $this->resolveIri($payload['paymentType'] ?? null, PaymentType::class, 'paymentType');
        $deliveryType = $this->resolveIri($payload['deliveryType'] ?? null, DeliveryType::class, 'deliveryType');

        $groups = $payload['groups'] ?? null;
        if (!is_array($groups) || $groups === [] || !array_is_list($groups)) {
            throw new BadRequestHttpException('"groups" must be a non-empty array of {onBehalfOfClient, shippingAddressId, billingAddressId, items}.');
        }

        $checkoutReference = $this->generateCheckoutReference();

        // Build and validate every order BEFORE touching the database: cheap
        // failures (bad IRIs, foreign addresses, duplicate clients, forbidden
        // delegation) never start a transaction.
        $orders = [];
        $seenClients = [];
        foreach ($groups as $index => $group) {
            $label = sprintf('groups[%d]', $index);
            if (!is_array($group)) {
                throw new BadRequestHttpException($label . ' must be an object.');
            }

            $onBehalfOf = $this->resolveIri($group['onBehalfOfClient'] ?? null, Client::class, $label . '.onBehalfOfClient');
            // Explicit (not stripped like a stray field on POST /orders): grouping
            // per client is the whole point of this endpoint, so a caller who may
            // not delegate gets a 403 rather than an order for the wrong company.
            $this->agentAuth->assertCanActFor($user, $onBehalfOf);

            $client = $onBehalfOf ?? $user->getClient();
            if ($client === null) {
                throw new BadRequestHttpException($label . ': your account is not linked to a company; set onBehalfOfClient.');
            }
            if (isset($seenClients[$client->getId()])) {
                throw new BadRequestHttpException(sprintf(
                    '%s: client "%s" appears in more than one group; merge its items into one group.',
                    $label,
                    $client->getName()
                ));
            }
            $seenClients[$client->getId()] = true;

            // Status is set here as well as in the processor (which only forces it
            // for non-admins): an admin batch must not keep the entity default.
            $order = (new Order())
                ->setUser($user)
                ->setIsDraft($isDraft)
                ->setStatus($isDraft ? Order::STATUS_DRAFT : Order::STATUS_NEW)
                ->setOnBehalfOfClient($onBehalfOf)
                ->setCustomerReference($customerReference)
                ->setNotes($notes)
                ->setPaymentType($paymentType)
                ->setDeliveryType($deliveryType)
                ->setCheckoutReference($checkoutReference);

            $this->applyAddresses($order, $group, $client, $isDraft, $label);
            $this->addItems($order, $group['items'] ?? null, $onBehalfOf, $label);
            $this->assertValid($order, $label);

            $orders[] = $order;
        }

        $this->persistAtomically($orders, $user);

        $this->dispatchCreatedMessages($orders, $user, $checkoutReference);

        return [
            'checkoutReference' => $checkoutReference,
            'orders' => array_map(
                fn (Order $order): array => (array) $this->normalizer->normalize($order, 'jsonld', ['groups' => self::NORMALIZATION_GROUPS]),
                $orders
            ),
        ];
    }

    /**
     * Run every order through OrderPriceProcessor inside ONE transaction.
     *
     * The processor's own purchase-limit debit uses $conn->transactional() and
     * the ORM flush uses beginTransaction()/commit(); with `use_savepoints: true`
     * (config/packages/doctrine.yaml) both nest as SAVEPOINTs inside ours, so a
     * failure in group N rolls back groups 1..N-1 too (orders AND debits).
     *
     * @param Order[] $orders
     */
    private function persistAtomically(array $orders, User $user): void
    {
        // Synthetic POST so the processor binds the user, forces status
        // new/draft and dispatches nothing (deferred) — as for POST /orders.
        $operation = new Post(uriTemplate: '/orders/checkout', name: 'order_checkout');
        $context = [OrderPriceProcessor::CONTEXT_DEFER_MESSAGES => true];

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($orders as $order) {
                $this->orderPriceProcessor->process($order, $operation, [], $context);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->warning('Checkout rolled back; no order was created', [
                'user' => $user->getEmail(),
                'groups' => count($orders),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->logger->info('Checkout committed', [
            'user' => $user->getEmail(),
            'checkout_reference' => $orders[0]->getCheckoutReference(),
            'order_ids' => array_map(static fn (Order $o): ?int => $o->getId(), $orders),
        ]);
    }

    /**
     * Same message/metadata OrderPriceProcessor would have sent per order, but
     * only now that every order is committed. Dispatch failures are logged, not
     * raised — the orders exist, and failing here would invite a duplicate retry.
     *
     * @param Order[] $orders
     */
    private function dispatchCreatedMessages(array $orders, User $user, string $checkoutReference): void
    {
        $modifiedBy = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getFullName(),
        ];

        foreach ($orders as $order) {
            if ($order->isDraft()) {
                continue; // drafts never notify (same as the processor)
            }
            try {
                $message = new OrderCreatedMessage((int) $order->getId());
                $message->addMetadata('modifiedBy', $modifiedBy);
                $message->addMetadata('operation', 'checkout');
                $this->messageBus->dispatch($message);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to dispatch OrderCreatedMessage after checkout', [
                    'order_id' => $order->getId(),
                    'checkout_reference' => $checkoutReference,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Shipping: required unless draft; must be an active delivery address of the
     * group's client. Billing: optional; defaults to the client's first active
     * billing address (text stays null when it has none).
     *
     * @param array<string, mixed> $group
     */
    private function applyAddresses(Order $order, array $group, Client $client, bool $isDraft, string $label): void
    {
        $shippingId = $this->readNullableInt($group, 'shippingAddressId', $label);
        $billingId = $this->readNullableInt($group, 'billingAddressId', $label);

        if ($shippingId === null) {
            if (!$isDraft) {
                throw new BadRequestHttpException(sprintf('%s: shippingAddressId is required for client "%s".', $label, $client->getName()));
            }
        } else {
            $shipping = $this->em->find(Address::class, $shippingId);
            if ($shipping === null || !$this->belongsTo($shipping, $client) || !$shipping->getIsDelivery() || !$shipping->getIsActive()) {
                throw new BadRequestHttpException(sprintf(
                    '%s: shippingAddressId %d is not an active delivery address of client "%s".',
                    $label,
                    $shippingId,
                    $client->getName()
                ));
            }
            $order->setShippingAddressId($shipping->getId());
            $order->setShippingAddress($shipping->getFullAddress());
        }

        if ($billingId !== null) {
            $billing = $this->em->find(Address::class, $billingId);
            if ($billing === null || !$this->belongsTo($billing, $client) || !$billing->getIsBilling() || !$billing->getIsActive()) {
                throw new BadRequestHttpException(sprintf(
                    '%s: billingAddressId %d is not an active billing address of client "%s".',
                    $label,
                    $billingId,
                    $client->getName()
                ));
            }
        } else {
            $billing = $this->em->getRepository(Address::class)->findOneBy(
                ['client' => $client, 'isBilling' => true, 'isActive' => true],
                ['id' => 'ASC']
            );
        }
        if ($billing !== null) {
            $order->setBillingAddress($billing->getFullAddress());
        }
    }

    private function belongsTo(Address $address, Client $client): bool
    {
        return $address->getClient()?->getId() === $client->getId();
    }

    /**
     * @param mixed $items the group's "items" value, expected list<{product: IRI, quantity: int}>
     */
    private function addItems(Order $order, mixed $items, ?Client $onBehalfOf, string $label): void
    {
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            throw new BadRequestHttpException($label . '.items must be a non-empty array of {product, quantity}.');
        }

        foreach ($items as $i => $item) {
            $itemLabel = sprintf('%s.items[%d]', $label, $i);
            if (!is_array($item)) {
                throw new BadRequestHttpException($itemLabel . ' must be an object.');
            }

            $product = $this->resolveIri($item['product'] ?? null, Product::class, $itemLabel . '.product');
            if ($product === null) {
                throw new BadRequestHttpException($itemLabel . '.product is required.');
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity)) {
                // Tolerate "2" / 2.0 (form inputs), reject anything else.
                if (!is_numeric($quantity) || (string) (int) $quantity !== ltrim((string) $quantity, '+')) {
                    throw new BadRequestHttpException($itemLabel . '.quantity must be a positive integer.');
                }
                $quantity = (int) $quantity;
            }
            if ($quantity < 1) {
                throw new BadRequestHttpException($itemLabel . '.quantity must be a positive integer.');
            }

            $orderItem = (new OrderItem())
                ->setProduct($product)
                ->setQuantity($quantity)
                ->setOnBehalfOfClient($onBehalfOf);
            $order->addItem($orderItem);
        }
    }

    /**
     * Entity constraints (e.g. customerReference length) that the API Platform
     * validator would have applied on POST /orders. Reported as 400 with the
     * group label so the caller knows which order is wrong.
     */
    private function assertValid(Order $order, string $label): void
    {
        $violations = $this->validator->validate($order);
        if (count($violations) === 0) {
            return;
        }

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
        }

        throw new BadRequestHttpException(sprintf('%s: %s', $label, implode(' ', $messages)));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null null when $iri is null/empty
     */
    private function resolveIri(mixed $iri, string $class, string $field): ?object
    {
        if ($iri === null || $iri === '') {
            return null;
        }
        if (!is_string($iri)) {
            throw new BadRequestHttpException(sprintf('%s must be an IRI string (e.g. "/api/v1/%ss/1").', $field, strtolower((new \ReflectionClass($class))->getShortName())));
        }

        try {
            $resource = $this->iriConverter->getResourceFromIri($iri, ['fetch_data' => true]);
        } catch (ApiPlatformException $e) {
            throw new BadRequestHttpException(sprintf('%s: unknown resource "%s".', $field, $iri), $e);
        }

        if (!$resource instanceof $class) {
            throw new BadRequestHttpException(sprintf(
                '%s must reference a %s, "%s" is not one.',
                $field,
                (new \ReflectionClass($class))->getShortName(),
                $iri
            ));
        }

        return $resource;
    }

    /** @param array<string, mixed> $data */
    private function readBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? false;
        if (!is_bool($value)) {
            throw new BadRequestHttpException(sprintf('"%s" must be a boolean.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function readNullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BadRequestHttpException(sprintf('"%s" must be a string or null.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function readNullableInt(array $data, string $key, string $label): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new BadRequestHttpException(sprintf('%s.%s must be a positive integer id or null.', $label, $key));
        }

        return $value;
    }

    /** e.g. "CHK-20261018-A1B2C3": one per request, shared by all its orders. */
    private function generateCheckoutReference(): string
    {
        $suffix = '';
        $max = strlen(self::REFERENCE_ALPHABET) - 1;
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::REFERENCE_ALPHABET[random_int(0, $max)];
        }

        return sprintf('CHK-%s-%s', (new \DateTimeImmutable())->format('Ymd'), $suffix);
    }
}
