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
 * ERP-facing order numbers (Nov 2026): a submitted order gets the next number
 * of the numeric sequence that continues the legacy webshop's series, whatever
 * path submitted it; drafts keep the provisional "ORD-…" number until then,
 * and a number never changes once given.
 */
final class OrderNumberSequenceApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private Client $company;
    private User $customer;
    private Product $product;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->company = (new Client())->setName('Numbered GmbH ' . $this->suffix)->setCode('ON-' . $this->suffix);
        $this->em->persist($this->company);

        $this->customer = (new User())
            ->setEmail('customer-' . $this->suffix)
            ->setFirstName('Test')
            ->setLastName('Customer');
        $this->customer->setPassword('not-used');
        $this->customer->setRoles(['ROLE_CLIENT']);
        $this->customer->setClient($this->company);
        $this->em->persist($this->customer);

        $this->product = (new Product())
            ->setName('Numbered part ' . $this->suffix)
            ->setSlug('numbered-part-' . $this->suffix)
            ->setPartNo('NP-' . $this->suffix)
            ->setPrice(10.0);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $conn = $this->em->getConnection();
                $like = ['s' => '%-' . $this->suffix];
                $conn->executeStatement('DELETE oi FROM order_item oi JOIN `order` o ON oi.order_ref_id = o.id JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s', $like);
                $conn->executeStatement('DELETE o FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s', $like);
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', $like);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', $like);
                $conn->executeStatement('DELETE FROM client WHERE code = :c', ['c' => 'ON-' . $this->suffix]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testSubmittedOrdersAreNumberedConsecutivelyAndDraftsAreNot(): void
    {
        $draft = $this->post(['isDraft' => true, 'items' => $this->items()]);
        self::assertSame(201, $this->httpStatus(), json_encode($draft));
        self::assertMatchesRegularExpression('/^ORD-[0-9A-F]{8}$/', $draft['orderNumber'], 'Drafts keep the provisional number');

        $first = $this->post(['isDraft' => false, 'items' => $this->items()]);
        self::assertSame(201, $this->httpStatus(), json_encode($first));
        self::assertMatchesRegularExpression('/^\d{7,}$/', $first['orderNumber'], 'A submitted order gets a plain numeric number');

        $second = $this->post(['isDraft' => false, 'items' => $this->items()]);
        self::assertSame((int) $first['orderNumber'] + 1, (int) $second['orderNumber'], 'Numbers are consecutive');

        // Submitting the draft numbers it too
        $submitted = $this->patch($draft['id'], ['isDraft' => false]);
        self::assertSame(200, $this->httpStatus(), json_encode($submitted));
        self::assertSame('new', $submitted['status']);
        self::assertSame((int) $second['orderNumber'] + 1, (int) $submitted['orderNumber']);

        // …and the number sticks on later edits
        $edited = $this->patch($draft['id'], ['notes' => 'please hurry']);
        self::assertSame(200, $this->httpStatus(), json_encode($edited));
        self::assertSame($submitted['orderNumber'], $edited['orderNumber']);
    }

    public function testTheSubmitEndpointNumbersTheDraft(): void
    {
        $draft = $this->post(['isDraft' => true, 'items' => $this->items()]);
        self::assertSame(201, $this->httpStatus(), json_encode($draft));

        $this->client->request('POST', "/api/v1/orders/{$draft['id']}/submit", [], [], $this->headers('application/ld+json'), '{}');
        $body = $this->body();

        self::assertSame(201, $this->httpStatus(), json_encode($body));
        self::assertMatchesRegularExpression('/^\d{7,}$/', $body['orderNumber']);
        self::assertSame('new', $body['status']);
    }

    // ------------------------------------------------------------------

    private function items(): array
    {
        return [['product' => '/api/v1/products/' . $this->product->getId(), 'quantity' => 1]];
    }

    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/v1/orders', [], [], $this->headers('application/ld+json'), json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->body();
    }

    private function patch(int $id, array $payload): array
    {
        $this->client->request('PATCH', '/api/v1/orders/' . $id, [], [], $this->headers('application/merge-patch+json'), json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->body();
    }

    private function headers(string $contentType): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->customer),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => $contentType,
        ];
    }

    private function body(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}
