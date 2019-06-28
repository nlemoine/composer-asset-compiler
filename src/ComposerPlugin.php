<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

/**
 * phpcs:disable Inpsyde.CodeQuality.NoAccessors
 *
 * @psalm-suppress MissingConstructor
 */
final class ComposerPlugin implements
    PluginInterface,
    EventSubscriberInterface,
    Capable,
    CommandProvider
{

    private const MODE_NONE = 0;
    private const MODE_COMMAND = 1;
    private const MODE_COMPOSER_INSTALL = 4;
    private const MODE_COMPOSER_UPDATE = 8;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var int
     */
    private $mode = self::MODE_NONE;

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'post-install-cmd' => 'run',
            'post-update-cmd' => 'run',
        ];
    }

    /**
     * @return array
     */
    public function getCapabilities(): array
    {
        return [CommandProvider::class => __CLASS__];
    }

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return [new CompileAssetsCommand()];
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     *
     * @psalm-suppress MissingReturnType
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = new Io($io);
    }

    /**
     * @param Event $event
     */
    public function onAutorunBecauseInstall(Event $event): void
    {
        $this->config = $this->initConfig(null, (bool)$event->isDevMode());
        if (!$this->config->autoRun()) {
            return;
        }

        $this->mode or $this->mode = self::MODE_COMPOSER_INSTALL;
        $this->run();
    }

    /**
     * @param Event $event
     */
    public function onAutorunBecauseUpdate(Event $event): void
    {
        $this->mode = self::MODE_COMPOSER_UPDATE;
        $this->onAutorunBecauseInstall($event);
    }

    /**
     * @param string|null $env
     * @param bool $isDev
     */
    public function runByCommand(?string $env, bool $isDev): void
    {
        $this->config = $this->initConfig($env, $isDev);
        $this->mode = self::MODE_COMMAND;
        $this->run();
    }

    /**
     * @param string|null $env
     * @param bool $isDev
     * @return Config
     */
    private function initConfig(?string $env, bool $isDev): Config
    {
        if ($env === null) {
            $env = getenv('COMPOSER_ASSETS_COMPILER')
                ?: $_SERVER['COMPOSER_ASSETS_COMPILER']
                ?? $_ENV['COMPOSER_ASSETS_COMPILER']
                ?? null;
        }

        /** @var RootPackageInterface $rootPackage */
        $rootPackage =  $this->composer->getPackage();

        return new Config(
            $rootPackage,
            new EnvResolver(is_string($env) ? $env : null, $isDev),
            new Filesystem(),
            $this->io
        );
    }

    /**
     * @return void
     */
    private function run(): void
    {
        $this->convertErrorsToExceptions();
        $exit = 0;

        try {
            $this->io->logo();
            $this->io->writeInfo('', 'starting...', '');

            /** @var Package[] $packages */
            $packages = $this->findPackages();
            if (!$packages) {
                return;
            }

            $exec = new ProcessExecutor();

            /** @var Package $firstPackage */
            $firstPackage = reset($packages);

            /** @var string $path */
            $path = $firstPackage->path();

            $commands = $this->config->commands($exec, $path);

            if (!$commands->isValid()) {
                throw new \Exception(
                    'Could not found a valid package manager. '
                    . 'Make sure either Yarn or npm are installed.'
                );
            }

            if (!$this->processPackages($commands, $exec, ...$packages)) {
                throw new \Exception("Assets compilation stopped due to failure.");
            }
        } catch (\Throwable $throwable) {
            $exit = 1;
            $this->io->writeError($throwable->getMessage());
            $this->io->writeVerboseError(...explode("\n", $throwable->getTraceAsString()));
        } finally {
            restore_error_handler();
            if ($this->mode === self::MODE_COMMAND) {
                exit($exit);
            }
        }
    }

    /**
     * @return array<int,Package>
     */
    private function findPackages(): array
    {
        /** @var RepositoryManager $manager */
        $manager = $this->composer->getRepositoryManager();

        /** @var RepositoryInterface $repo */
        $repo = $manager->getLocalRepository();

        /** @var InstallationManager $installationManager */
        $installationManager = $this->composer->getInstallationManager();

        /** @var RootPackageInterface $root */
        $root =  $this->composer->getPackage();

        $factory = new PackageFactory(
            $this->config->envResolver(),
            $this->config->filesystem(),
            $installationManager
        );

        /** @var array<string,Package> $packages */
        $packages = $this->config->packagesFinder()->find(
            $repo,
            $root,
            $factory,
            $this->config->autoDiscover()
        );

        if (!$packages) {
            $this->io->writeVerboseComment('Nothing to process.');

            return [];
        }

        /** @var array<int,Package> $packages */
        $packages = array_values($packages);

        return $packages;
    }

    /**
     * @param Commands $commands
     * @param ProcessExecutor $exec
     * @param Package ...$packages
     * @return bool
     */
    private function processPackages(
        Commands $commands,
        ProcessExecutor $exec,
        Package ...$packages
    ): bool {

        $locker = new Locker($this->io, $this->config->envResolver()->env());

        foreach ($packages as $package) {
            if (!$this->processPackage($package, $commands, $exec, $locker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Package $package
     * @param Commands $commands
     * @param ProcessExecutor $executor
     * @param Locker $locker
     * @return bool
     */
    private function processPackage(
        Package $package,
        Commands $commands,
        ProcessExecutor $executor,
        Locker $locker
    ): bool {

        $name = $package->name();

        /** @var string $path */
        $path = $package->path();

        try {
            if ($locker->isLocked($package)) {
                $this->io->writeVerboseComment("Skipping '{$name}' because already compiled.");

                return true;
            }

            $this->io->writeComment("Start processing '{$name}'...");
            $shouldWipe = $this->config->wipeAllowed($path);

            $doneDeps = $this->doDependencies($package, $commands, $executor);
            $doneDeps or $failure = true;

            $doneScript = null;
            if (($doneDeps === null || $doneDeps === true)) {
                $doneScript = $this->doScript($package, $commands, $executor);
                $doneScript or $failure = true;
            }

            $doneWipe = null;
            if ($doneDeps && ($doneScript === null || $doneScript === true) && $shouldWipe) {
                $doneWipe = $this->wipeNodeModules($path);
            }

            $failedDeps = is_bool($doneDeps) && !$doneDeps ? 'failed dependency installation' : '';
            $failedScript = is_bool($doneScript) && !$doneScript ? 'failed script execution' : '';
            $failedWipe = is_bool($doneWipe) && !$doneWipe ? 'failed node_modules wiping' : '';

            if (!$failedDeps && !$failedScript && !$failedWipe) {
                $this->io->writeComment("  Processing of '{$name}' done.");
                $locker->lock($package);

                return true;
            }

            $messages = ["  Processing of '{$name}' terminated with errors:"];
            $failedDeps and $messages[] = "   - {$failedDeps}";
            $failedScript and $messages[] = "   - {$failedScript}";
            $failedWipe and $messages[] = "   - {$failedWipe}";

            $this->io->writeError(...$messages);
        } catch (\Throwable $throwable) {
            $failure = true;
            $this->io->writeError("  Processing of '{$name}' terminated with errors.");
            $this->io->writeVerboseError($throwable->getMessage());
            $this->io->writeVerboseError(...explode("\n", $throwable->getTraceAsString()));
        }

        if (!($failure ?? false)) {
            $locker->lock($package);

            return true;
        }

        return !$this->config->stopOnFailure();
    }

    /**
     * @param Package $package
     * @param Commands $commands
     * @param ProcessExecutor $executor
     * @return bool|null
     */
    private function doDependencies(
        Package $package,
        Commands $commands,
        ProcessExecutor $executor
    ): ?bool {

        $doneDeps = null;
        $out = null;
        $cwd = $package->path();

        if ($package->update()) {
            $this->io->writeVerboseComment("  - updating...");
            $doneDeps = $executor->execute($commands->updateCmd(), $out, $cwd) === 0;
        } elseif ($package->install()) {
            $this->io->writeVerboseComment("  - installing...");
            $doneDeps = $executor->execute($commands->installCmd(), $out, $cwd) === 0;
        }

        if (!is_bool($doneDeps)) {
            return null;
        }

        if ($doneDeps) {
            $this->io->writeVerboseInfo('    success!');

            return true;
        }

        $this->io->writeVerboseError('    failed!');
        $out and $this->io->writeVerboseError("    {$out}");

        return $doneDeps;
    }

    /**
     * @param Package $package
     * @param Commands $commands
     * @param ProcessExecutor $executor
     * @return bool|null
     */
    private function doScript(
        Package $package,
        Commands $commands,
        ProcessExecutor $executor
    ): ?bool {

        /** @var string[] $scripts */
        $scripts = $package->script();
        if (!$scripts) {
            return null;
        }

        $all = 0;
        $done = 0;
        foreach ($scripts as $script) {
            $command = $commands->scriptCmd($script);
            $this->io->writeVerboseComment("  - executing '{$command}'...");
            $all++;
            if ($executor->execute($command, $out, $package->path()) === 0) {
                $this->io->writeVerboseInfo('    success!');
                $done++;
                continue;
            }

            $this->io->writeVerboseError('    failed!');
            $out and $this->io->writeVerboseError("    {$out}");
        }

        return $all === $done;
    }

    /**
     * @param string $baseDir
     * @return bool|null
     */
    private function wipeNodeModules(string $baseDir): ?bool
    {
        $filesystem = $this->config->filesystem();
        $dir = rtrim((string)$filesystem->normalizePath($baseDir), '/') . "/node_modules";
        if (!is_dir($dir)) {
            $this->io->writeVerboseComment("  - '{$dir}' not found, nothing to wipe.");

            return null;
        }

        $this->io->writeVerboseComment("  - wiping '{$dir}'...");

        /** @var bool $doneWipe */
        $doneWipe = $filesystem->removeDirectory($dir);
        $doneWipe
            ? $this->io->writeVerboseInfo('    success!')
            : $this->io->writeVerboseError('    failed!');

        return $doneWipe;
    }

    /**
     * @return void
     */
    private function convertErrorsToExceptions(): void
    {
        /** @psalm-suppress MixedArgumentTypeCoercion */
        set_error_handler(
            function (int $severity, string $message, string $file = '', int $line = 0) {

                if ($file && $line) {
                    $message = rtrim($message, '. ') . ", in {$file} line {$line}.";
                }

                throw new \Exception($message, $severity);
            },
            E_ALL
        );
    }
}