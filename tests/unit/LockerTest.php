<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\IO\IOInterface;
use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Io;
use Inpsyde\AssetsCompiler\Locker;
use Inpsyde\AssetsCompiler\Package;
use Inpsyde\AssetsCompiler\PackageConfig;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Mockery;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class LockerTest extends TestCase
{

    public function testIsLockedIsFalseIfNoFileExists()
    {
        $locker = new Locker(new Io(Mockery::mock(IOInterface::class)), 'x');

        static::assertFalse($locker->isLocked($this->factorPackage(['script' => 'test'])));
    }

    /** @noinspection PhpParamsInspection */
    public function testIsLockedIsFalseForEmptyFileAndErrorWritten()
    {
        $io = Mockery::mock(Io::class);
        $io->shouldReceive('writeVerboseError')
            ->once()
            ->andReturnUsing(
                static function (string $arg) {
                    static::assertStringContainsString('Could not read content of lock file', $arg);
                }
            );

        $file = (new vfsStreamFile('.composer_compiled_assets', 0777))->withContent('');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($file);

        $locker = new Locker($io, 'x');
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertTrue(file_exists($package->path() . '/.composer_compiled_assets'));

        static::assertFalse($locker->isLocked($package));
    }

    public function testIsLockedIsFalseIfHashDiffers()
    {
        $lockFile = (new vfsStreamFile('.composer_compiled_assets', 0777))->withContent('x');
        $packagesJson = (new vfsStreamFile('package.json', 0777))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);
        $dir->addChild($lockFile);

        $locker = new Locker(new Io(Mockery::mock(IOInterface::class)), 'x');
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertTrue(file_exists($package->path() . '/package.json'));
        static::assertTrue(file_exists($package->path() . '/.composer_compiled_assets'));

        static::assertFalse($locker->isLocked($package));
    }

    public function testIsLockedIsFalseBeforeLockAndTrueAfterThat()
    {
        $packagesJson = (new vfsStreamFile('package.json', 0777))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        $locker = new Locker(new Io(Mockery::mock(IOInterface::class)), 'x');
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertFalse($locker->isLocked($package));

        $locker->lock($package);

        static::assertTrue($locker->isLocked($package));
        static::assertTrue($locker->isLocked($package));
    }

    /** @noinspection PhpParamsInspection */
    public function testErrorWrittenIfPackagesJsonIsNotReadable()
    {
        $io = Mockery::mock(Io::class);
        $io->shouldReceive('writeVerboseError')
            ->once()
            ->andReturnUsing(
                static function (string $arg): void {
                    static::assertStringContainsString('Could not read content of', $arg);
                }
            );

        $lockFile = (new vfsStreamFile('.composer_compiled_assets', 0777))->withContent('x');
        $packagesJson = (new vfsStreamFile('package.json', 0000))->withContent('{"x": "y"}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);
        $dir->addChild($lockFile);

        $locker = new Locker($io, 'x');
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertFalse($locker->isLocked($package));
    }

    /** @noinspection PhpParamsInspection */
    public function testErrorWrittenOnWriteIfDirNotWritable()
    {
        $io = Mockery::mock(Io::class);
        $io->shouldReceive('writeVerboseError')
            ->once()
            ->andReturnUsing(
                static function (string $arg): void {
                    static::assertStringContainsString('Could not write lock file', $arg);
                }
            );

        $packagesJson = (new vfsStreamFile('package.json', 0444))->withContent('{"x": "y"}');

        $dir = vfsStream::setup('exampleDir', 0444);
        $dir->addChild($packagesJson);

        $locker = new Locker($io, 'x');
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertFalse($locker->isLocked($package));

        $locker->lock($package);

        static::assertFalse($locker->isLocked($package));
    }

    /**
     * @param array $settings
     * @param string|null $dir
     * @param string $name
     * @return \Inpsyde\AssetsCompiler\Package
     */
    private function factorPackage(
        array $settings,
        ?string $dir = null,
        string $name = 'foo'
    ): Package {

        $config = PackageConfig::forRawPackageData($settings, new EnvResolver('', false));

        return Package::new($name, $config, $dir ?? __DIR__);
    }
}
