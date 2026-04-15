<?php

namespace App\Command;

use App\Entity\MediaItem;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:import-product-images',
    description: 'Import product images from a directory, matching files to products by part number',
)]
class ImportProductImagesCommand extends Command
{
    private SymfonyStyle $io;
    private Filesystem $filesystem;

    private const MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly string $mediaDirectory,
    ) {
        parent::__construct();
        $this->filesystem = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Path to the image directory')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate import without writing data or files')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Clear existing images before import (default: skip products with images)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $overwrite = $input->getOption('overwrite');
        $sourceDir = $input->getOption('source');

        $this->io->title('Product Image Import');

        if (!$sourceDir) {
            $this->io->error('The --source option is required.');
            return Command::FAILURE;
        }

        if (!is_dir($sourceDir)) {
            $this->io->error(sprintf('Source directory does not exist: %s', $sourceDir));
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->io->note('DRY RUN — no files will be copied or database records created.');
        }

        // 1. Scan and group image files by product code
        $this->io->section('Scanning source directory');
        $imageGroups = $this->scanImageDirectory($sourceDir);
        $this->io->writeln(sprintf('Found <info>%d</info> image files across <info>%d</info> product codes',
            array_sum(array_map('count', $imageGroups)),
            count($imageGroups)
        ));

        // 2. Load all products indexed by lowercase part_no
        $this->io->section('Matching to database products');
        $products = $this->productRepository->findAll();
        $productMap = [];
        foreach ($products as $product) {
            $partNo = strtolower(trim($product->getPartNo() ?? ''));
            if ($partNo !== '') {
                $productMap[$partNo] = $product;
            }
        }

        // 3. Calculate matches
        $matched = [];
        $unmatched = [];
        foreach ($imageGroups as $code => $files) {
            if (isset($productMap[$code])) {
                $matched[$code] = $files;
            } else {
                $unmatched[] = $code;
            }
        }

        $this->io->writeln(sprintf('Matched: <info>%d</info> product codes', count($matched)));
        if (count($unmatched) > 0) {
            $this->io->writeln(sprintf('Unmatched (skipped): <comment>%d</comment> codes', count($unmatched)));
        }

        if (count($matched) === 0) {
            $this->io->warning('No matching products found. Nothing to import.');
            return Command::SUCCESS;
        }

        // 4. Prepare upload directory
        $uploadDir = $this->mediaDirectory . '/products';
        if (!$dryRun) {
            $this->filesystem->mkdir($uploadDir);
        }

        // 5. Import images
        $this->io->section('Importing images');
        $stats = ['products' => 0, 'images' => 0, 'skipped' => 0, 'errors' => 0];
        $batchCount = 0;

        try {
            foreach ($matched as $code => $files) {
                $product = $productMap[$code];

                // Check if product already has images
                if (!$overwrite && $product->getImageGallery()->count() > 0) {
                    $stats['skipped']++;
                    continue;
                }

                // If overwrite, clear existing gallery and featured image
                if ($overwrite && !$dryRun) {
                    foreach ($product->getImageGallery() as $existing) {
                        $oldPath = $this->mediaDirectory . '/' . ltrim($existing->getFilePath(), '/uploads/');
                        if ($this->filesystem->exists($oldPath)) {
                            $this->filesystem->remove($oldPath);
                        }
                        $this->entityManager->remove($existing);
                    }
                    $product->setFeaturedImage(null);
                }

                // Sort files by sequence number
                usort($files, fn($a, $b) => $a['sequence'] <=> $b['sequence']);

                foreach ($files as $fileInfo) {
                    if ($dryRun) {
                        $this->io->writeln(sprintf('  [DRY] %s → product #%d (%s)%s',
                            $fileInfo['filename'],
                            $product->getId(),
                            $product->getPartNo(),
                            $fileInfo['sequence'] === 1 ? ' [FEATURED]' : ''
                        ));
                        $stats['images']++;
                        continue;
                    }

                    try {
                        // Copy file
                        $destPath = $uploadDir . '/' . $fileInfo['filename'];
                        $this->filesystem->copy($fileInfo['fullPath'], $destPath);

                        // Create MediaItem
                        $mediaItem = new MediaItem();
                        $mediaItem->setFilename($fileInfo['filename']);
                        $mediaItem->setMimeType($fileInfo['mimeType']);
                        $mediaItem->setFilePath('/uploads/products/' . $fileInfo['filename']);
                        $mediaItem->setFileSize(filesize($destPath));
                        $mediaItem->setProduct($product);

                        $this->entityManager->persist($mediaItem);

                        // First image becomes featured
                        if ($fileInfo['sequence'] === 1) {
                            $product->setFeaturedImage($mediaItem);
                        }

                        $stats['images']++;
                    } catch (\Exception $e) {
                        $this->io->warning(sprintf('Failed to import %s: %s', $fileInfo['filename'], $e->getMessage()));
                        $stats['errors']++;
                    }
                }

                $stats['products']++;
                $batchCount++;

                if (!$dryRun && $batchCount % 50 === 0) {
                    $this->entityManager->flush();
                    $this->io->writeln(sprintf('  Flushed batch (%d products processed)', $batchCount));
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            $this->io->error(['Import failed:', $e->getMessage()]);
            return Command::FAILURE;
        }

        // 6. Summary
        $this->io->newLine();
        $this->io->table(
            ['Metric', 'Count'],
            [
                ['Products updated', $stats['products']],
                ['Images imported', $stats['images']],
                ['Products skipped (already have images)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->io->success(sprintf('%sImport complete. %d images for %d products.', $prefix, $stats['images'], $stats['products']));

        return Command::SUCCESS;
    }

    private function scanImageDirectory(string $dir): array
    {
        $groups = [];
        $files = scandir($dir);

        foreach ($files as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $filename;
            if (!is_file($fullPath)) {
                continue;
            }

            // Parse filename: {product-code}_{sequence}.{ext}
            if (!preg_match('/^([a-z0-9-]+)_(\d+)\.(jpg|jpeg|png|webp)$/i', $filename, $matches)) {
                $this->io->writeln(sprintf('  <comment>Skipping unrecognized file: %s</comment>', $filename));
                continue;
            }

            $productCode = strtolower($matches[1]);
            $sequence = (int) $matches[2];
            $ext = strtolower($matches[3]);

            if (!isset(self::MIME_MAP[$ext])) {
                continue;
            }

            $groups[$productCode][] = [
                'filename' => $filename,
                'fullPath' => $fullPath,
                'sequence' => $sequence,
                'mimeType' => self::MIME_MAP[$ext],
            ];
        }

        ksort($groups);
        return $groups;
    }
}
