<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\ClientProductPrice;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientProductPrice>
 *
 * @method ClientProductPrice|null find($id, $lockMode = null, $lockVersion = null)
 * @method ClientProductPrice|null findOneBy(array $criteria, array $orderBy = null)
 * @method ClientProductPrice[]    findAll()
 * @method ClientProductPrice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ClientProductPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientProductPrice::class);
    }

    /**
     * One page of a client's product prices with product, featured image and
     * gallery fetch-joined; filtering and pagination happen in SQL so only the
     * requested page is hydrated (the shop listing previously loaded the
     * client's entire price list and filtered in PHP).
     *
     * When $search is set it matches name OR partNo and the individual
     * name/partNo filters are ignored, mirroring the previous PHP filter.
     *
     * @return array{0: ClientProductPrice[], 1: int} [page items, total matching rows]
     */
    public function findFilteredPageForClient(
        Client $client,
        ?string $search,
        ?string $productName,
        ?string $productPartNo,
        int $page,
        int $itemsPerPage
    ): array {
        $qb = $this->createQueryBuilder('cpp')
            ->addSelect('p', 'fi', 'ig')
            ->join('cpp.product', 'p')
            ->leftJoin('p.featuredImage', 'fi')
            ->leftJoin('p.imageGallery', 'ig')
            ->andWhere('cpp.client = :client')
            ->setParameter('client', $client)
            ->orderBy('cpp.id', 'ASC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('p.name LIKE :search OR p.partNo LIKE :search')
                ->setParameter('search', '%' . $this->escapeLike($search) . '%');
        } else {
            if ($productName !== null && $productName !== '') {
                $qb->andWhere('p.name LIKE :productName')
                    ->setParameter('productName', '%' . $this->escapeLike($productName) . '%');
            }
            if ($productPartNo !== null && $productPartNo !== '') {
                $qb->andWhere('p.partNo LIKE :productPartNo')
                    ->setParameter('productPartNo', '%' . $this->escapeLike($productPartNo) . '%');
            }
        }

        $query = $qb->getQuery()
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        // imageGallery is a to-many fetch join: the Paginator paginates by
        // distinct root ids so the LIMIT applies to prices, not joined rows.
        $paginator = new Paginator($query, true);

        return [iterator_to_array($paginator), count($paginator)];
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /**
     * Find a custom price for a specific client and product
     */
    public function findCustomPrice(Client $client, Product $product): ?ClientProductPrice
    {
        return $this->findOneBy([
            'client' => $client,
            'product' => $product
        ]);
    }

    /**
     * Find all valid custom prices for a specific client
     *
     * @param Client $client
     * @return ClientProductPrice[]
     */
    public function findValidPricesForClient(Client $client): array
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('cpp')
            ->andWhere('cpp.client = :client')
            ->andWhere('(cpp.validFrom IS NULL OR cpp.validFrom <= :now)')
            ->andWhere('(cpp.validUntil IS NULL OR cpp.validUntil >= :now)')
            ->setParameter('client', $client)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all custom prices for a product
     *
     * @param Product $product
     * @return ClientProductPrice[]
     */
    public function findByProduct(Product $product): array
    {
        return $this->findBy(['product' => $product]);
    }

    /**
     * Find all expired custom prices
     *
     * @return ClientProductPrice[]
     */
    public function findExpiredPrices(): array
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('cpp')
            ->andWhere('cpp.validUntil IS NOT NULL')
            ->andWhere('cpp.validUntil < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Save a custom price to the database
     */
    public function save(ClientProductPrice $clientProductPrice, bool $flush = true): void
    {
        $this->getEntityManager()->persist($clientProductPrice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a custom price from the database
     */
    public function remove(ClientProductPrice $clientProductPrice, bool $flush = true): void
    {
        $this->getEntityManager()->remove($clientProductPrice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
