<?php

declare(strict_types=1);

namespace App\Tests\Unit\Carrier;

use App\Service\Carrier\MyDhl\DhlLabelStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DhlLabelStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dhl-labels-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testSaveAndResolve(): void
    {
        $storage = new DhlLabelStorage($this->dir);

        $name = $storage->save('1234567890', 'label', '%PDF-1.4');

        self::assertSame('1234567890-label.pdf', $name);
        self::assertSame('%PDF-1.4', file_get_contents((string) $storage->getPath($name)));
    }

    public function testRejectsPathsOutsideTheStorageDirectory(): void
    {
        $storage = new DhlLabelStorage($this->dir);
        $storage->save('1234567890', 'label', '%PDF-1.4');

        self::assertNull($storage->getPath('../1234567890-label.pdf'));
        self::assertNull($storage->getPath('/etc/passwd'));
        self::assertNull($storage->getPath('missing-label.pdf'));
    }

    public function testSanitizesFileNameParts(): void
    {
        $storage = new DhlLabelStorage($this->dir);

        self::assertSame('123evil-label.pdf', $storage->save('../123/evil', 'label', 'x'));
    }
}
