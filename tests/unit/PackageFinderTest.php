<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\Installer\InstallationManager;
use Composer\Package\Package as ComposerPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\RootConfig;
use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Io;
use Inpsyde\AssetsCompiler\Package;
use Inpsyde\AssetsCompiler\PackageFactory;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class PackageFinderTest extends TestCase
{
    public function testNoRootSettingsAndAutoDiscover()
    {
        $found = $this->findPackages(null, 'test', true);

        static::assertCount(3, $found);
        static::assertArrayHasKey('me/foo', $found);
        static::assertArrayHasKey('me/bar', $found);

        /** @var Package $foo */
        $foo = $found['me/foo'];
        /** @var Package $bar */
        $bar = $found['me/bar'];

        static::assertSame(['my-name-is-foo'], $foo->script());
        static::assertSame(['my-name-is-bar --default'], $bar->script());
    }

    public function testNoRootSettingsAndAutoNoDiscover()
    {
        $found = $this->findPackages(['auto-discover' => false], 'test', true);

        static::assertSame([], $found);
    }

    public function testRootSettingsWithFallbackButNoPackageSettingsAndNoAutoDiscover()
    {
        $found = $this->findPackages(
            [
                'packages' => [
                    'me/baz-*' => true,
                ],
                'auto-discover' => false,
                'stop-on-failure' => false,
            ],
            'test',
            true
        );

        static::assertSame([], $found);
    }

    public function testNoSettingsAndNoDefaultsMakeFailureWhenStopOnFailureIsTrue()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/me\/baz-package/');

        $this->findPackages(
            [
                'packages' => [
                    'me/baz-package' => true,
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ],
            'test',
            true
        );
    }

    public function testForceDefaultsFailsIfNoDefaults()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/me\/baz-package/');

        $this->findPackages(
            [
                'packages' => [
                    'me/baz-package' => 'force-defaults',
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ],
            'test',
            true
        );
    }

    public function testExclude()
    {
        $found = $this->findPackages(
            [
                'packages' => [
                    'me/foo' => false,
                ],
            ],
            'test',
            true
        );

        static::assertCount(2, $found);
        static::assertArrayHasKey('me/bar', $found);
        static::assertArrayHasKey('last/with-env', $found);

        $bar = $found['me/bar'];

        static::assertSame(['my-name-is-bar --default'], $bar->script());
    }

    public function testForceDefaults()
    {
        $found = $this->findPackages(
            [
                'packages' => [
                    'me/*' => 'force-defaults',
                ],
                'defaults' => [
                    'dependencies' => 'update',
                    'script' => ['foo', 'bar'],
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ],
            'test',
            true
        );

        static::assertCount(3, $found);

        /** @var Package $package */
        foreach ($found as $name => $package) {
            static::assertSame($name, $package->name());
            static::assertFalse($package->isInstall());
            static::assertTrue($package->isUpdate());
            static::assertSame(['foo', 'bar'], $package->script());
        }
    }

    /**
     * @param array|null $settings
     * @param string $env
     * @param bool $isDev
     * @return array{0:RootPackage, 1:Config}
     */
    private function factoryRootAndConfig(?array $settings, string $env, bool $isDev): array
    {
        $root = new RootPackage('company/my-root-package', '1.0', '1.0.0.0');

        if ($settings) {
            $root->setExtra(['composer-asset-compiler' => $settings]);
        }

        $config = new RootConfig(
            $root,
            new EnvResolver($env, $isDev),
            new Filesystem(),
            $this->factoryIo()
        );

        return [$root, $config];
    }

    /**
     * @param array $settings
     * @param string $env
     * @param bool $isDev
     * @param RootConfig|null $config
     * @return array
     * @noinspection PhpParamsInspection
     */
    private function findPackages(?array $settings, string $env, bool $isDev): array
    {
        /**
         * @var RootPackage $root
         * @var RootConfig $config
         */
        [$root, $config] = $this->factoryRootAndConfig($settings, $env, $isDev);

        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');
        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        /** @var \Mockery\MockInterface|InstallationManager $manager */
        $manager = \Mockery::mock(InstallationManager::class);
        $manager->shouldReceive('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturn($dir->url());

        $factory = new PackageFactory(
            $config->envResolver(),
            $config->filesystem(),
            $manager,
            $dir->url()
        );

        return $config
            ->packagesFinder()
            ->find($this->composerRepo(), $root, $factory, $config->autoDiscover());
    }

    /**
     * @return RepositoryInterface
     */
    private function composerRepo(): RepositoryInterface
    {
        $foo = new ComposerPackage('me/foo', '1.0', '1.0.0.0');
        $foo->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'my-name-is-foo',
                ],
            ]
        );

        $bar = new ComposerPackage('me/bar', '1.0', '1.0.0.0');
        $bar->setExtra(
            [
                'composer-asset-compiler' => [
                    'env' => [
                        '$default' => [
                            'script' => 'my-name-is-bar --default',
                        ],
                        '$default-no-dev' => [
                            'script' => 'my-name-is-bar --default-no-dev',
                        ],
                        'production' => [
                            'script' => 'my-name-is-bar --production',
                        ],
                    ],
                ],
            ]
        );

        $baz = new ComposerPackage('me/baz-package', '1.0', '1.0.0.0');

        $last = new ComposerPackage('last/with-env', '1.0', '1.0.0.0');
        $last->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'encore ${ENV_NAME}',
                ],
            ]
        );

        return new ArrayRepository([$foo, $bar, $baz, $last]);
    }
}
