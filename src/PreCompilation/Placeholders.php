<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Semver\VersionParser;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\EnvResolver;

class Placeholders
{
    public const ENV = 'env';
    public const HASH = 'hash';
    public const VERSION = 'version';
    public const REFERENCE = 'ref';

    /**
     * @var string
     */
    private $env;

    /**
     * @var string|null
     */
    private $hash;

    /**
     * @var string|null
     */
    private $version;

    /**
     * @var string|null
     */
    private $reference;

    /**
     * @var string
     */
    private $uid;

    /**
     * @param Asset $asset
     * @param string $env
     * @param string|null $hash
     * @return Placeholders
     */
    public static function new(Asset $asset, string $env, ?string $hash): Placeholders
    {
        return new self($env, $hash, $asset->version(), $asset->reference());
    }

    /**
     * @param string $env
     * @param string|null $hash
     * @param string|null $version
     * @param string|null $reference
     */
    private function __construct(string $env, ?string $hash, ?string $version, ?string $reference)
    {
        $this->env = $env;
        $this->hash = $hash;
        $this->version = $version;
        $this->reference = $reference;

        $str = md5(implode('|', [$env, $hash ?? '', $version ?? '', $reference ?? '']));
        $this->uid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($str, 8, 8),
            substr($str, 16, 4),
            substr($str, 20, 4),
            substr($str, 24, 4),
            substr(md5($str), 12, 12)
        );
    }

    /**
     * @return string
     */
    public function uuid(): string
    {
        return $this->uid;
    }

    /**
     * @return bool
     */
    public function hasStableVersion(): bool
    {
        return $this->version && VersionParser::parseStability($this->version) === 'stable';
    }

    /**
     * @param string $original
     * @param string $hash
     * @param array $environment
     * @return string
     */
    public function replace(string $original, array $environment): string
    {
        if (!$original || (strpos($original, '${') === false)) {
            return $original;
        }

        $replace = [
            self::HASH => $this->hash,
            self::ENV => $this->env,
            self::VERSION => $this->version,
            self::REFERENCE => $this->reference,
        ];

        $replaced = preg_replace_callback(
            '~\$\{\s*(' . implode('|', array_keys($replace)) . ')\s*\}~i',
            static function (array $matches) use ($replace): string {
                $key = strtolower((string)($matches[1] ?? ''));

                return $replace[$key] ?? '';
            },
            $original
        );

        return $replaced ? EnvResolver::replaceEnvVariables($replaced, $environment) : '';
    }
}