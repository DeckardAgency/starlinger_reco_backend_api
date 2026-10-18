<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Product;
use App\Entity\ProductProductLink;
use App\Entity\User;
use App\Enum\ProductLinkRelationType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP contract of product links (`/api/v1/product_product_links`): the two link
 * kinds ("alternative" | "replacement") travel as plain strings, the filtering
 * and ordering the admin/shop UIs rely on, one link per (parent, child), no
 * self-links, and the admin-only write rule.
 *
 * Follows TenantIsolationApiTest: WebTestCase, JWTs minted via the token manager,
 * own fixtures with a per-run suffix, cleaned up in tearDown.
 */
final class ProductProductLinkApiTest extends WebTestCase
{
    private const URL = '/api/v1/product_product_links';
    private const DUPLICATE_MESSAGE = 'This product is already linked to the parent product.';
    private const SELF_LINK_MESSAGE = 'A product cannot be linked to itself.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private User $admin;
    private User $shopUser;
    private Product $parent;
    private Product $childA;
    private Product $childB;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        $company = (new Client())
            ->setName('Link Test ' . $this->suffix)
            ->setCode('PPL-' . $this->suffix);
        $this->em->persist($company);

        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN'], null);
        $this->shopUser = $this->makeUser('shop', ['ROLE_CLIENT'], $company);

        $this->parent = $this->makeProduct('parent');
        $this->childA = $this->makeProduct('child-a');
        $this->childB = $this->makeProduct('child-b');

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Links -> products -> users -> clients, filtered to this run's fixtures.
        // Wrapped so a cleanup failure can never skip parent::tearDown().
        try {
            if (isset($this->em, $this->parent) && $this->em->isOpen()) {
                $like = '%-' . $this->suffix;
                $productIds = [$this->parent->getId(), $this->childA->getId(), $this->childB->getId()];
                $this->em->createQuery('DELETE App\Entity\ProductProductLink l WHERE l.parentProductId IN (:ids) OR l.childProductId IN (:ids)')
                    ->setParameter('ids', $productIds)->execute();
                $this->em->createQuery('DELETE App\Entity\Product p WHERE p.slug LIKE :s')
                    ->setParameter('s', 'ppl-' . $like)->execute();
                $this->em->createQuery('DELETE App\Entity\User u WHERE u.email LIKE :s')
                    ->setParameter('s', $like)->execute();
                $this->em->createQuery('DELETE App\Entity\Client c WHERE c.code LIKE :s')
                    ->setParameter('s', $like)->execute();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAdminCreatesBothKindsAndListsThemFilteredAndOrdered(): void
    {
        $alternative = $this->request('POST', self::URL, $this->admin, $this->payload($this->childA, 'alternative', 2));
        self::assertSame(201, $alternative['status'], json_encode($alternative['body']));
        self::assertSame('alternative', $alternative['body']['relationType'] ?? null, 'relationType is serialized as its plain string value.');
        self::assertArrayNotHasKey('relationTypeId', $alternative['body'], 'The legacy relationTypeId is gone from the contract.');

        $replacement = $this->request('POST', self::URL, $this->admin, $this->payload($this->childB, 'replacement', 1));
        self::assertSame(201, $replacement['status'], json_encode($replacement['body']));
        self::assertSame('replacement', $replacement['body']['relationType'] ?? null);

        // Round trip: the DB holds the plain string, the ORM hydrates the enum.
        $id = $replacement['body']['id'];
        self::assertSame(
            'replacement',
            $this->em->getConnection()->fetchOne('SELECT relation_type FROM product_product_link WHERE id = ?', [$id])
        );
        $this->em->clear();
        self::assertSame(ProductLinkRelationType::Replacement, $this->em->find(ProductProductLink::class, $id)?->getRelationType());

        $list = $this->request('GET', self::URL . '?parentProductId=' . $this->parent->getId() . '&itemsPerPage=100&order[ord]=asc', $this->admin);
        self::assertSame(200, $list['status']);
        self::assertSame(2, $list['body']['totalItems'] ?? null);
        self::assertSame(
            [$this->childB->getId(), $this->childA->getId()],
            array_column($list['body']['member'] ?? [], 'childProductId'),
            'order[ord]=asc must sort by ord.'
        );
        self::assertSame(['replacement', 'alternative'], array_column($list['body']['member'] ?? [], 'relationType'));

        $desc = $this->request('GET', self::URL . '?parentProductId=' . $this->parent->getId() . '&order[ord]=desc', $this->admin);
        self::assertSame(200, $desc['status']);
        self::assertSame([$this->childA->getId(), $this->childB->getId()], array_column($desc['body']['member'] ?? [], 'childProductId'));

        $replacementsOnly = $this->request('GET', self::URL . '?parentProductId=' . $this->parent->getId() . '&relationType=replacement', $this->admin);
        self::assertSame(200, $replacementsOnly['status']);
        self::assertSame(1, $replacementsOnly['body']['totalItems'] ?? null);
        self::assertSame($this->childB->getId(), $replacementsOnly['body']['member'][0]['childProductId'] ?? null);
    }

    public function testDuplicateLinkIsRejectedWith422(): void
    {
        $first = $this->request('POST', self::URL, $this->admin, $this->payload($this->childA, 'alternative'));
        self::assertSame(201, $first['status'], json_encode($first['body']));

        // Same (parent, child) with the other kind: still one link per pair.
        $duplicate = $this->request('POST', self::URL, $this->admin, $this->payload($this->childA, 'replacement'));
        self::assertSame(422, $duplicate['status'], 'A second link for the same pair is a validation error, not a DB error. Body: ' . json_encode($duplicate['body']));
        self::assertSame(self::DUPLICATE_MESSAGE, $this->firstViolation($duplicate['body'])['message'] ?? null);
    }

    public function testMissingRelationTypeIsRejectedWith422(): void
    {
        $payload = $this->payload($this->childA, 'alternative');
        unset($payload['relationType']);

        $response = $this->request('POST', self::URL, $this->admin, $payload);

        self::assertSame(422, $response['status'], json_encode($response['body']));
        self::assertSame('relationType', $this->firstViolation($response['body'])['propertyPath'] ?? null);
    }

    public function testUnknownRelationTypeIsRejectedWith422(): void
    {
        $response = $this->request('POST', self::URL, $this->admin, $this->payload($this->childA, 'bundle'));

        self::assertSame(422, $response['status'], 'An unknown kind must be a 422 violation, not a 400. Body: ' . json_encode($response['body']));
        self::assertSame('relationType', $this->firstViolation($response['body'])['propertyPath'] ?? null);
    }

    public function testSelfLinkIsRejectedWith422(): void
    {
        $response = $this->request('POST', self::URL, $this->admin, $this->payload($this->parent, 'alternative'));

        self::assertSame(422, $response['status'], json_encode($response['body']));
        self::assertSame(self::SELF_LINK_MESSAGE, $this->firstViolation($response['body'])['message'] ?? null);
    }

    public function testPatchChangesRelationType(): void
    {
        $created = $this->request('POST', self::URL, $this->admin, $this->payload($this->childA, 'alternative'));
        self::assertSame(201, $created['status'], json_encode($created['body']));
        $id = $created['body']['id'];

        $patched = $this->request('PATCH', self::URL . '/' . $id, $this->admin, ['relationType' => 'replacement'], 'application/merge-patch+json');
        self::assertSame(200, $patched['status'], json_encode($patched['body']));
        self::assertSame('replacement', $patched['body']['relationType'] ?? null);
        self::assertSame($this->childA->getId(), $patched['body']['childProductId'] ?? null, 'PATCH leaves the other fields alone.');

        $read = $this->request('GET', self::URL . '/' . $id, $this->admin);
        self::assertSame(200, $read['status']);
        self::assertSame('replacement', $read['body']['relationType'] ?? null);
    }

    public function testShopUserCanReadButNotWrite(): void
    {
        $created = $this->request('POST', self::URL, $this->admin, $this->payload($this->childA, 'replacement'));
        self::assertSame(201, $created['status'], json_encode($created['body']));

        $list = $this->request('GET', self::URL . '?parentProductId=' . $this->parent->getId(), $this->shopUser);
        self::assertSame(200, $list['status']);
        self::assertSame(1, $list['body']['totalItems'] ?? null);
        self::assertSame('replacement', $list['body']['member'][0]['relationType'] ?? null);

        $forbidden = $this->request('POST', self::URL, $this->shopUser, $this->payload($this->childB, 'alternative'));
        self::assertSame(403, $forbidden['status'], 'Writes on product links are ROLE_ADMIN only.');
    }

    // --- helpers ------------------------------------------------------------

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

    /** @return array<string,mixed> */
    private function payload(Product $child, string $relationType, ?int $ord = null): array
    {
        $payload = [
            'parentProductId' => $this->parent->getId(),
            'childProductId' => $child->getId(),
            'relationType' => $relationType,
        ];
        if ($ord !== null) {
            $payload['ord'] = $ord;
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function firstViolation(array $body): array
    {
        return $body['violations'][0] ?? [];
    }

    /** @param string[] $roles */
    private function makeUser(string $tag, array $roles, ?Client $client): User
    {
        $user = (new User())
            ->setEmail('ppl-' . $tag . '-' . $this->suffix)
            ->setFirstName('Link')
            ->setLastName($tag);
        $user->setPassword('not-used-in-tests');
        $user->setRoles($roles);
        if ($client !== null) {
            $user->setClient($client);
        }
        $this->em->persist($user);

        return $user;
    }

    private function makeProduct(string $tag): Product
    {
        $product = (new Product())
            ->setName('PPL ' . $tag . ' ' . $this->suffix)
            ->setSlug('ppl-' . $tag . '-' . $this->suffix)
            ->setPrice(10.0);
        $this->em->persist($product);

        return $product;
    }
}
