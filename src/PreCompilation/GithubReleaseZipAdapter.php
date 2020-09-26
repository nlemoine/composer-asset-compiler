<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Package\Package;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloader;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloaderFactory;
use Inpsyde\AssetsCompiler\Util\HttpClient;
use Inpsyde\AssetsCompiler\Util\Io;

class GithubReleaseZipAdapter implements Adapter
{
    private const REPO = 'repository';
    private const TOKEN = 'token';
    private const TOKEN_USER = 'user';

    /**
     * @var Io
     */
    private $io;

    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var ArchiveDownloaderFactory
     */
    private $downloaderFactory;

    /**
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $archiveDownloaderFactory
     * @return GithubReleaseZipAdapter
     */
    public static function new(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ): GithubReleaseZipAdapter {

        return new self($io, $client, $archiveDownloaderFactory);
    }

    /**
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $archiveDownloaderFactory
     */
    private function __construct(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ) {

        $this->io = $io;
        $this->client = $client;
        $this->downloaderFactory = $archiveDownloaderFactory;
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return 'gh-release-zip';
    }

    /**
     * @param string $name
     * @param string $hash
     * @param string $source
     * @param string $targetDir
     * @param array $config
     * @param string|null $version
     * @return bool
     */
    public function tryPrecompiled(
        string $name,
        string $hash,
        string $source,
        string $targetDir,
        array $config,
        ?string $version
    ): bool {

        try {
            if (!$version) {
                $this->io->writeVerboseError('  Invalid configuration for GitHub release zip.');

                return false;
            }

            [$endpoint, $owner] = $this->buildEndpoint($source, $config, $version);
            if (!$endpoint || !$owner) {
                $this->io->writeVerboseError('  Invalid configuration for GitHub release zip.');

                return false;
            }

            $distUrl = $this->retrieveArchiveUrl($source, $endpoint, $owner, $config);

            $type = ArchiveDownloader::ZIP;
            $package = new Package($name, 'stable', 'stable');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);
            $package->setTransportOptions(
                [
                    'http' => [
                        'header' => [
                            'Accept: application/octet-stream',
                        ],
                    ],
                ]
            );
            $this->downloaderFactory->create($type)->download($package, $targetDir);

            return true;
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return false;
        }
    }

    /**
     * @param string $source
     * @param array $config
     * @param string $version
     * @return array{string,string}|array{null,null}
     */
    private function buildEndpoint(string $source, array $config, string $version): array
    {
        if (!$source) {
            return [null, null];
        }

        $repo = $config[self::REPO] ?? null;
        $userRepo = ($repo && is_string($repo)) ? explode('/', $repo) : null;
        if (!$userRepo || count($userRepo) !== 2) {
            return [null, null];
        }

        $endpoint = "https://api.github.com/repos/{$repo}/releases/tags/{$version}";
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return [null, null];
        }

        $safe = filter_var($endpoint, FILTER_SANITIZE_URL);
        $safe && is_string($safe) or $safe = null;

        return $safe ? [$safe, reset($userRepo) ?: null] : [null, null];
    }

    /**
     * @param string $source
     * @param array $config
     * @return string
     */
    private function retrieveArchiveUrl(
        string $assetsName,
        string $endpoint,
        string $owner,
        array $config
    ): string {

        $token = $config[self::TOKEN] ?? null;
        $repo = $config[self::REPO] ?? '';
        $authString = '';
        if ($token) {
            $user = $config[self::TOKEN_USER] ?? $owner;
            $authString = "https://{$user}:{$token}@";
            $endpoint = (string)preg_replace('~^https://(.+)~', $authString . '$1', $endpoint);
        }

        $response = $this->client->get($endpoint);
        $json = $response ? json_decode($response, true) : null;
        if (!$json || !is_array($json) || empty($json['assets'])) {
            throw new \Exception("Could not obtain a valid API response from {$endpoint}.");
        }

        if (strtolower(pathinfo($assetsName, PATHINFO_EXTENSION) ?: '') !== 'zip') {
            $assetsName .= '.zip';
        }

        $id = null;
        foreach ((array)$json['assets'] as $assetData) {
            $name = is_array($assetData) ? $assetData['name'] : null;
            $id = $name ? ($assetData['id'] ?? null) : null;
            if (($name === $assetsName) && $id) {
                break;
            }
        }

        if (!$id) {
            $this->io->writeVerbose("  Release zip '{$assetsName}' not found in '{$repo}'.");

            return '';
        }

        return $authString
            ? "{$authString}api.github.com/repos/{$repo}/releases/assets/{$id}"
            : "https://api.github.com/repos/{$repo}/releases/assets/{$id}";
    }
}
