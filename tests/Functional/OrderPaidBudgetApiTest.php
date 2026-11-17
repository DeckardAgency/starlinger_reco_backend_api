<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "paid" order status (Nov 2026) and what it does to the client budget:
 *  - submitting an order debits the client's open payments (amountSpent);
 *  - marking it paid (waiting_for_payment → paid, delivered → paid) stamps paidAt
 *    and credits the amount back — once;
 *  - cancelling an UNPAID order credits it back, cancelling a PAID one does not;
 *  - processing may continue after payment (paid → in_process) with paidAt kept;
 *  - a customer cannot mark their own order paid.
 */
final class OrderPaidBudgetApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private Client $company;
    private User $customer;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->company = (new Client())
            ->setName('Paid Test ' . $this->suffix)
            ->setCode('PT-' . $this->suffix)
            ->setPurchaseLimit('1000.00')
            ->setAmountSpent('0.00');
        $this->em->persist($this->company);

        $this->customer = $this->makeUser('customer', ['ROLE_CLIENT'], $this->company);
        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN'], null);

        $this->product = (new Product())
            ->setName('Paid Product ' . $this->suffix)
            ->setSlug('paid-product-' . $this->suffix)
            ->setPartNo('PP-' . $this->suffix)
            ->setPrice(100.0);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $conn = $this->em->getConnection();
                $like = '%-' . $this->suffix;
                $conn->executeStatement(
                    'DELETE oi FROM order_item oi JOIN `order` o ON oi.order_ref_id = o.id
                     JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE o FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM client WHERE code LIKE :s', ['s' => $like]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testPaymentFreesTheBudgetAndProcessingContinues(): void
    {
        $orderId = $this->submitOrder(2); // 2 x 100
        self::assertSame(200.0, $this->openPayments());

        $r = $this->setStatus($orderId, 'waiting_for_payment', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertNull($r['body']['paidAt']);
        self::assertSame(200.0, $this->openPayments(), 'Waiting for payment is still open.');

        $r = $this->setStatus($orderId, 'paid', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertSame('paid', $r['body']['status']);
        self::assertNotNull($r['body']['paidAt'], 'paidAt is stamped when the order becomes paid.');
        self::assertSame(0.0, $this->openPayments(), 'The paid amount is no longer an open payment.');

        // Prepayment: processing continues; the payment fact stays.
        $r = $this->setStatus($orderId, 'in_process', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertNotNull($r['body']['paidAt']);
        self::assertSame(0.0, $this->openPayments());

        // Cancelling a paid order must not credit it a second time.
        $r = $this->setStatus($orderId, 'canceled', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertSame(0.0, $this->openPayments());
    }

    public function testCancellingAnUnpaidOrderCreditsItBack(): void
    {
        $orderId = $this->submitOrder(3); // 300
        self::assertSame(300.0, $this->openPayments());

        $r = $this->setStatus($orderId, 'canceled', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertSame(0.0, $this->openPayments());
    }

    public function testDeliveredOrderCanBePaidAfterwards(): void
    {
        $orderId = $this->submitOrder(1); // 100
        self::assertSame(100.0, $this->openPayments());

        // Admins may jump the fulfilment states; the invoice is paid after delivery.
        $r = $this->setStatus($orderId, 'delivered', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertSame(100.0, $this->openPayments(), 'Delivered but unpaid keeps blocking the budget.');

        $r = $this->setStatus($orderId, 'paid', $this->admin);
        self::assertSame(200, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertNotNull($r['body']['paidAt']);
        self::assertSame(0.0, $this->openPayments());
    }

    public function testCustomerCannotMarkTheirOrderPaid(): void
    {
        $orderId = $this->submitOrder(1);

        $r = $this->setStatus($orderId, 'paid', $this->customer);
        self::assertSame(400, $r['status'], 'No new → paid transition, and only admins may force a status. Body: ' . json_encode($r['body']));
        self::assertSame(100.0, $this->openPayments());
    }

    // --- helpers ------------------------------------------------------------

    private function submitOrder(int $qty): int
    {
        $r = $this->request('POST', '/api/v1/orders', $this->customer, [
            'isDraft' => false,
            'items' => [['product' => '/api/v1/products/' . $this->product->getId(), 'quantity' => $qty]],
        ]);
        self::assertSame(201, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertSame('new', $r['body']['status']);
        self::assertNull($r['body']['paidAt'] ?? null);

        return (int) $r['body']['id'];
    }

    /** @return array{status:int, body:array<mixed>} */
    private function setStatus(int $orderId, string $status, User $as): array
    {
        $payload = ['status' => $status];
        if ($status === 'canceled') {
            $payload['cancellationReason'] = 'test'; // required by validateAndSetCanceledFields
        }

        return $this->request('PATCH', '/api/v1/orders/' . $orderId, $as, $payload, 'application/merge-patch+json');
    }

    private function openPayments(): float
    {
        return (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(amount_spent, 0) FROM client WHERE id = :id',
            ['id' => $this->company->getId()]
        );
    }

    /** @param string[] $roles */
    private function makeUser(string $tag, array $roles, ?Client $client): User
    {
        $u = (new User())
            ->setEmail($tag . '-' . $this->suffix)
            ->setFirstName('Test')->setLastName(ucfirst($tag));
        $u->setPassword('not-used');
        $u->setRoles($roles);
        if ($client !== null) {
            $u->setClient($client);
        }
        $this->em->persist($u);

        return $u;
    }

    /** @return array{status:int, body:array<mixed>} */
    private function request(string $method, string $url, User $as, ?array $json = null, string $contentType = 'application/ld+json'): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($as),
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
        $content = null;
        if ($json !== null) {
            $server['CONTENT_TYPE'] = $contentType;
            $content = json_encode($json, JSON_THROW_ON_ERROR);
        }
        $this->client->request($method, $url, [], [], $server, $content);
        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
