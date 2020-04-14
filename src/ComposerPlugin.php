<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

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
use Symfony\Component\Process\Process;

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
     *
     * @see ComposerPlugin::onAutorunBecauseInstall()
     * @see ComposerPlugin::onAutorunBecauseUpdate()
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'post-install-cmd' => [
                ['onAutorunBecauseInstall', 0],
            ],
            'post-update-cmd' => [
                ['onAutorunBecauseUpdate', 0],
            ],
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
            $env = EnvResolver::readEnv('COMPOSER_ASSETS_COMPILER');
        }

        /** @var RootPackageInterface $rootPackage */
        $rootPackage = $this->composer->getPackage();

        return new Config(
            $rootPackage,
            new EnvResolver($env, $isDev),
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
            if ($exit || $this->mode === self::MODE_COMMAND) {
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
        $root = $this->composer->getPackage();

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
            if ($locker->isLocked($package)) {
                $name = $package->name();
                $this->io->writeVerboseComment("Skipping '{$name}' because already compiled.");
                continue;
            }

            if ($this->processPackage($package, $commands, $exec)) {
                $locker->lock($package);
                continue;
            }

            if ($this->config->stopOnFailure()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Package $package
     * @param Commands $commands
     * @param ProcessExecutor $executor
     * @return bool
     */
    private function processPackage(
        Package $package,
        Commands $commands,
        ProcessExecutor $executor
    ): bool {

        $name = $package->name();

        /** @var string $path */
        $path = $package->path();

        try {
            $this->io->writeComment("Start processing '{$name}'...");
            $shouldWipe = $this->config->wipeAllowed($path);

            $doneDeps = $this->doDependencies($package, $commands, $executor);
            $success = $doneDeps !== false;

            $doneScript = null;
            if ($success) {
                $doneScript = $this->doScript($package, $commands, $executor) !== false;
                $success = $doneDeps !== false;
            }

            $doneWipe = null;
            if ($doneDeps && ($doneScript === null || $doneScript === true) && $shouldWipe) {
                $doneWipe = $this->wipeNodeModules($path);
            }

            $failedDeps = $doneDeps === false ? 'failed dependency installation' : '';
            $failedScript = $doneScript === false ? 'failed script execution' : '';
            $failedWipe = $doneWipe === false ? 'failed node_modules wiping' : '';

            if (!$failedDeps && !$failedScript && !$failedWipe) {
                $this->io->writeComment("  Processing of '{$name}' done.");

                return true;
            }

            // If `wipeNodeModules` fails, we show an error message, but we'll still return true

            $messages = ["  Processing of '{$name}' terminated with errors:"];
            $failedDeps and $messages[] = "   - {$failedDeps}";
            $failedScript and $messages[] = "   - {$failedScript}";
            $failedWipe and $messages[] = "   - {$failedWipe}";

            $this->io->writeError(...$messages);
        } catch (\Throwable $throwable) {
            $success = false;
            $this->io->writeError("  Processing of '{$name}' terminated with errors.");
            $this->io->writeVerboseError($throwable->getMessage());
            $this->io->writeVerboseError(...explode("\n", $throwable->getTraceAsString()));
        }

        return $success;
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

        $isUpdate = $package->isUpdate();
        $isInstall = $package->isInstall();

        if (!$isUpdate && !$isInstall) {
            return null;
        }

        $this->io->writeVerboseComment($isUpdate ? '  - updating...' : '  - installing...');

        /** @var string $command */
        $command = $isUpdate
            ? $commands->updateCmd($this->io)
            : $commands->installCmd($this->io);

        return $this->executeCommand($executor, $command, $package->path());
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
        $packageEnv = $package->env();
        foreach ($scripts as $script) {
            $all++;
            /** @var string $command */
            $command = $commands->scriptCmd($script, $packageEnv);
            $this->io->writeVerboseComment("  - executing '{$command}'...");
            $this->executeCommand($executor, $command, $package->path()) and $done++;
        }

        return $all === $done;
    }

    /**
     * @param ProcessExecutor $executor
     * @param string $command
     * @param string|null $cwd
     * @return bool
     */
    private function executeCommand(ProcessExecutor $executor, string $command, ?string $cwd): bool
    {
        static $outputHandler;
        $outputHandler or $outputHandler = function (string $type, string $buffer): void {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                Process::ERR === $type
                    ? $this->io->writeVerboseError('    ' . trim($line))
                    : $this->io->writeVerbose('    ' . trim($line));
            }
        };

        $success = $executor->execute($command, $outputHandler, $cwd) === 0;
        $success
            ? $this->io->writeVerboseInfo('    success!')
            : $this->io->writeVerboseError('    failed!');

        return $success;
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
