<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\DhlShipment;
use App\Entity\Order;
use App\Entity\User;
use App\Service\Carrier\MyDhl\DhlLabelStorage;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * DHL shipments and their label PDFs carry customer addresses: admin-only,
 * including for the customer whose order it is.
 *
 * Builds its own fixtures with a unique per-run suffix and cleans them up.
 */
final class DhlShipmentAccessApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private User $admin;
    private User $customer;
    private DhlShipment $shipment;
    private ?string $labelPath = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));
        $awb = (string) random_int(1000000000, 9999999999);

        $company = (new Client())->setName('DHL Test ' . $this->suffix)->setCode('DT-' . $this->suffix);
        $this->em->persist($company);

        $this->admin = $this->user('admin', ['ROLE_ADMIN'], null);
        $this->customer = $this->user('customer', ['ROLE_CLIENT'], $company);

        $order = (new Order())
            ->setOrderNumber('DT-' . $this->suffix)
            ->setStatus(Order::STATUS_SHIPPED)
            ->setTotalAmount(10.0)
            ->setUser($this->customer);
        $this->em->persist($order);

        $storage = self::getContainer()->get(DhlLabelStorage::class);
        $fileName = $storage->save($awb, 'label', '%PDF-1.4 test label');
        $this->labelPath = $storage->getPath($fileName);

        $this->shipment = (new DhlShipment())
            ->setOrder($order)
            ->setAwb($awb)
            ->setProductCode('U')
            ->setPlannedShippingAt(new \DateTime('tomorrow 14:00'))
            ->setTotalWeight('2.000')
            ->addDocument('label', $fileName);
        $this->em->persist($this->shipment);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->labelPath && is_file($this->labelPath)) {
                unlink($this->labelPath);
            }
            if (isset($this->em) && $this->em->isOpen()) {
                $conn = $this->em->getConnection();
                // dhl_shipment cascades from order
                $conn->executeStatement("DELETE FROM `order` WHERE order_number = :n", ['n' => 'DT-' . $this->suffix]);
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', ['s' => '%-' . $this->suffix]);
                $conn->executeStatement('DELETE FROM client WHERE code = :c', ['c' => 'DT-' . $this->suffix]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAdminDownloadsLabel(): void
    {
        $this->get($this->admin, $this->labelUrl());

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function testCustomerCannotDownloadLabelOfOwnOrder(): void
    {
        $this->get($this->customer, $this->labelUrl());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownDocumentTypeIs404(): void
    {
        $this->get($this->admin, '/api/v1/dhl_shipments/' . $this->shipment->getId() . '/documents/invoice');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testShipmentResourceIsAdminOnly(): void
    {
        $this->get($this->customer, '/api/v1/dhl_shipments/' . $this->shipment->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->get($this->admin, '/api/v1/dhl_shipments/' . $this->shipment->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($this->shipment->getAwb(), $body['awb']);
        self::assertSame(['label'], $body['documentTypes']);
        // Storage file names are internal
        self::assertArrayNotHasKey('documents', $body);
    }

    private function labelUrl(): string
    {
        return '/api/v1/dhl_shipments/' . $this->shipment->getId() . '/documents/label';
    }

    private function get(User $user, string $url): void
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
    }

    private function user(string $name, array $roles, ?Client $company): User
    {
        $user = (new User())
            ->setEmail($name . '-' . $this->suffix)
            ->setFirstName('Test')
            ->setLastName(ucfirst($name));
        $user->setPassword('not-used');
        $user->setRoles($roles);
        if ($company) {
            $user->setClient($company);
        }
        $this->em->persist($user);

        return $user;
    }
}
