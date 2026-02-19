<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;
use App\Controller\CreateMediaItemAction;
use App\Repository\MediaItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: MediaItemRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['media_item:read']]),
        new GetCollection(normalizationContext: ['groups' => ['media_item:read']]),
        new Post(
            controller: CreateMediaItemAction::class,
            openapi: new Operation(
                requestBody: new RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary'
                                    ]
                                ]
                            ]
                        ]
                    ])
                )
            ),
            validationContext: ['groups' => ['Default', 'media_item:create']],
            output: MediaItem::class,
            deserialize: false
        ),
        new Delete(),
        new Patch(normalizationContext: ['groups' => ['media_item:read']])
    ],
    normalizationContext: ['groups' => ['media_item:read']],
    denormalizationContext: ['groups' => ['media_item:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'mimeType' => 'exact'
])]
class MediaItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['media_item:read', 'documentation:read', 'documentation:item', 'payment_type:read', 'delivery_type:read', 'warehouse:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['media_item:write', 'media_item:create'])]
    #[Groups(['media_item:read', 'media_item:write', 'documentation:read', 'documentation:item', 'payment_type:read', 'delivery_type:read', 'warehouse:read'])]
    private ?string $filename = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['media_item:write', 'media_item:create'])]
    #[Groups(['media_item:read', 'media_item:write', 'documentation:read', 'documentation:item', 'payment_type:read', 'delivery_type:read', 'warehouse:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['media_item:write', 'media_item:create'])]
    #[Groups(['media_item:read', 'media_item:write', 'documentation:read', 'documentation:item', 'payment_type:read', 'delivery_type:read', 'warehouse:read'])]
    private ?string $filePath = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['media_item:read', 'payment_type:read', 'delivery_type:read', 'warehouse:read'])]
    private ?int $fileSize = null;

    #[Assert\NotNull(groups: ['media_item:create'])]
    #[Assert\File(
        maxSize: '5M',
        mimeTypes: [
            // Images
            'image/jpeg',
            'image/png',
            'image/webp',
            // PDF
            'application/pdf',
            // Excel files
            'application/vnd.ms-excel', // .xls
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            // Optional: Word documents
            'application/msword', // .doc
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            // Optional: Other common formats
            'text/csv', // .csv
            'text/plain',// .txt
        ],
        groups: ['media_item:create']
    )]
    private ?File $file = null;

    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'featuredImage')]
    private Collection $products;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['media_item:read', 'documentation:read', 'documentation:item'])]
    private ?\DateTimeInterface $createdAt;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['media_item:read', 'documentation:read', 'documentation:item'])]
    private ?\DateTimeInterface $updatedAt;

    #[ORM\ManyToOne(inversedBy: 'imageGallery')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Product $productDocument = null;

    #[ORM\ManyToOne(targetEntity: Documentation::class, inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['media_item:read', 'media_item:write'])]
    private ?Documentation $documentation = null;

    #[ORM\ManyToOne(targetEntity: PaymentType::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?PaymentType $paymentTypeDocument = null;

    #[ORM\ManyToOne(targetEntity: DeliveryType::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?DeliveryType $deliveryTypeDocument = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Warehouse $warehouseDocument = null;

    public function __construct()
    {
        $this->products = new ArrayCollection();

        // Set default values to prevent null values
        $this->filename = "placeholder.jpg";
        $this->mimeType = "image/jpeg";
        $this->filePath = "/uploads/placeholder.jpg";
    }

    public function __toString(): string
    {
        return $this->filename ?? 'New Media Item';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): static
    {
        $this->file = $file;
        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setFeaturedImage($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            if ($product->getFeaturedImage() === $this) {
                $product->setFeaturedImage(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getProductDocument(): ?Product
    {
        return $this->productDocument;
    }

    public function setProductDocument(?Product $productDocument): static
    {
        $this->productDocument = $productDocument;

        return $this;
    }

    public function getDocumentation(): ?Documentation
    {
        return $this->documentation;
    }

    public function setDocumentation(?Documentation $documentation): static
    {
        $this->documentation = $documentation;

        return $this;
    }

    public function getPaymentTypeDocument(): ?PaymentType
    {
        return $this->paymentTypeDocument;
    }

    public function setPaymentTypeDocument(?PaymentType $paymentTypeDocument): static
    {
        $this->paymentTypeDocument = $paymentTypeDocument;

        return $this;
    }

    public function getDeliveryTypeDocument(): ?DeliveryType
    {
        return $this->deliveryTypeDocument;
    }

    public function setDeliveryTypeDocument(?DeliveryType $deliveryTypeDocument): static
    {
        $this->deliveryTypeDocument = $deliveryTypeDocument;

        return $this;
    }

    public function getWarehouseDocument(): ?Warehouse
    {
        return $this->warehouseDocument;
    }

    public function setWarehouseDocument(?Warehouse $warehouseDocument): static
    {
        $this->warehouseDocument = $warehouseDocument;

        return $this;
    }
}
