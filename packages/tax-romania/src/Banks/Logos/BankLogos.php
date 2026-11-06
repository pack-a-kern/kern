<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Logos;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Bank logos, from one folder this installation fills — never shipped, never downloaded.
 *
 * ## Where a bank's logo is looked for
 *
 * `banks.logos.path` (`BANKS_LOGOS_PATH`, by default `storage/app/private/bank-logos`), then the
 * bank's own country code, then the bank: `<path>/RO/BTRLRO22.svg`. Every part after the path comes
 * from the bank's row — two capital letters, then its BIC's first eight characters or its LEI — so
 * nothing anybody types becomes part of a path, and nothing is read from any other folder.
 *
 * Links are not followed — not the country folder, not the file — and a file is read only when its
 * resolved path is inside the logo folder. Every file is checked when the folder is indexed and
 * again when it is served, so a file swapped for a link in between is refused rather than followed.
 *
 * ## What counts as a logo
 *
 * - **Named after its bank**, in capitals as both are written: its BIC's first eight characters
 *   (`BTRLRO22`); without a BIC, its LEI; and without either — a branch, which is no legal entity and
 *   has no LEI of its own — its parent's LEI, since a branch is its parent bank. Anything else in the
 *   folder is ignored.
 * - **An image**, by content as well as by name: SVG, WebP or PNG, whose first bytes say so. Where a
 *   bank has two files, that order decides.
 * - **At most `banks.logos.max_bytes`.**
 *
 * An SVG can carry script. It is served with a policy that runs none (`ShowBankLogoController`) and
 * shown through `<img>`, where a browser runs none either.
 *
 * ## The index
 *
 * Which bank has which file, kept in the cache and rebuilt on demand — "Read again" on the settings
 * screen, or `kern:banks:logos` — never per request. Rebuilt by itself when the path is not the one it
 * was built for.
 *
 * Read from the cache once per instance, not once per bank: a list of banks asks for each one's logo,
 * and every ask used to unpack the whole index again. The class is not a singleton, so an instance —
 * and what it holds, a few dozen small objects — lasts one request or one job.
 */
final class BankLogos
{
    public const string CACHE_KEY = 'tax-romania.banks.logos';

    /** The route a logo is served from — `ShowBankLogoController`. */
    public const string ROUTE = 'tax-romania.banks.logo';

    private const string COUNTRY = '/\A[A-Z]{2}\z/';

    private const string LOGO_NAME = '/\A([A-Z0-9]{8}|[A-Z0-9]{20})\.(svg|webp|png)\z/';

    /** In precedence order: where one bank has two files, the earlier type wins. */
    private const array TYPES = [
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'png' => 'image/png',
    ];

    /** @var array{path: string, logos: array<string, BankLogo>}|null the index as this instance last read it */
    private ?array $read = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ) {
    }

    /** The folder logos are read from, as configured — whether or not it exists yet. */
    public function path(): string
    {
        return rtrim($this->config->string('banks.logos.path'), '/');
    }

    /** Whether that folder exists and can be read. */
    public function available(): bool
    {
        return $this->root() !== null;
    }

    /**
     * Where a bank's file goes, relative to the folder — `RO/BTRLRO22`, plus any of the three
     * extensions — or null for a bank with no BIC, no LEI and no parent's LEI.
     */
    public static function fileFor(string $countryCode, ?string $bic, ?string $lei, ?string $parentLei): ?string
    {
        $key = self::keysOf($bic, $lei, $parentLei)[0] ?? null;

        return $key === null ? null : strtoupper($countryCode) . '/' . $key;
    }

    /** A bank's logo, from its country's folder — or null. */
    public function for(string $countryCode, ?string $bic, ?string $lei, ?string $parentLei): ?BankLogo
    {
        $index = $this->index();
        $country = strtoupper($countryCode);

        foreach (self::keysOf($bic, $lei, $parentLei) as $key) {
            if (isset($index[$country . '/' . $key])) {
                return $index[$country . '/' . $key];
            }
        }

        return null;
    }

    /**
     * The names a bank's file may have, in the order they are tried. The parent's LEI only for a bank
     * with neither of its own: a subsidiary is a bank of its own, with a logo of its own.
     *
     * @return list<string>
     */
    private static function keysOf(?string $bic, ?string $lei, ?string $parentLei): array
    {
        $own = array_values(array_filter([$bic === null ? null : substr($bic, 0, 8), $lei], is_string(...)));

        return $own !== [] || $parentLei === null ? $own : [$parentLei];
    }

    /** The logo's bytes, checked again on the path they are read from — or null. */
    public function contents(BankLogo $logo): ?string
    {
        $root = $this->root();

        return $root === null ? null : $this->readable($root, $logo->file);
    }

    /**
     * Reads the folder again, and keeps what it found.
     *
     * @return array<string, BankLogo> by `COUNTRY/KEY`
     */
    public function refresh(): array
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->read = null;

        return $this->index();
    }

    /** @return array<string, BankLogo> */
    private function index(): array
    {
        $path = $this->path();

        if ($this->read === null || $this->read['path'] !== $path) {
            $this->read = ['path' => $path, 'logos' => $this->cachedIndex($path)];
        }

        return $this->read['logos'];
    }

    /** @return array<string, BankLogo> */
    private function cachedIndex(string $path): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && ($cached['path'] ?? null) === $path && is_array($cached['logos'] ?? null)) {
            /** @var array<string, array{file: string, mime_type: string, fingerprint: string}> $rows */
            $rows = $cached['logos'];

            return array_map(BankLogo::fromArray(...), $rows);
        }

        $logos = $this->scan();
        $this->cache->forever(self::CACHE_KEY, [
            'path' => $path,
            'logos' => array_map(static fn (BankLogo $logo): array => $logo->toArray(), $logos),
        ]);

        return $logos;
    }

    /** @return array<string, BankLogo> */
    private function scan(): array
    {
        $root = $this->root();

        if ($root === null) {
            return [];
        }

        $found = [];
        $ranks = [];
        $order = array_flip(array_keys(self::TYPES));

        foreach (self::entries($root) as $country) {
            if (preg_match(self::COUNTRY, $country) !== 1 || is_link($root . '/' . $country) || ! is_dir($root . '/' . $country)) {
                continue;
            }

            foreach (self::entries($root . '/' . $country) as $name) {
                if (preg_match(self::LOGO_NAME, $name, $match) !== 1) {
                    continue;
                }

                [, $key, $extension] = $match;
                $id = $country . '/' . $key;

                if (isset($ranks[$id]) && $ranks[$id] <= $order[$extension]) {
                    continue;
                }

                $file = $country . '/' . $name;
                $contents = $this->readable($root, $file);

                if ($contents === null || ! self::looksLike($extension, $contents)) {
                    continue;
                }

                $found[$id] = new BankLogo($file, self::TYPES[$extension], substr(sha1($contents), 0, 12));
                $ranks[$id] = $order[$extension];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * A file's bytes, when it is a plain file inside the folder and within the size limit.
     *
     * Resolved and compared, not trusted: the index's own names are already safe, and this is the
     * second check that keeps them so.
     */
    private function readable(string $root, string $file): ?string
    {
        $path = $root . '/' . $file;
        $real = realpath($path);

        if ($real === false || is_link($path) || is_link(dirname($path)) || ! str_starts_with($real, $root . '/') || ! is_file($real)) {
            return null;
        }

        $size = filesize($real);

        if ($size === false || $size === 0 || $size > $this->config->integer('banks.logos.max_bytes')) {
            return null;
        }

        $contents = file_get_contents($real);

        return $contents === false ? null : $contents;
    }

    /** Whether the bytes are the image the name says: the format's own opening, not the extension alone. */
    private static function looksLike(string $extension, string $contents): bool
    {
        return match ($extension) {
            'png' => str_starts_with($contents, "\x89PNG\r\n\x1a\n"),
            'webp' => str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP',
            'svg' => preg_match('/\A(\xEF\xBB\xBF)?\s*</', $contents) === 1
                && preg_match('/<svg[\s>]/i', substr($contents, 0, 4096)) === 1,
            default => false,
        };
    }

    /** The folder's resolved path, when it is a directory. */
    private function root(): ?string
    {
        $root = realpath($this->path());

        return $root !== false && is_dir($root) ? $root : null;
    }

    /** @return list<string> a directory's entries, without `.`, `..` and hidden files */
    private static function entries(string $directory): array
    {
        $entries = scandir($directory);

        return $entries === false ? [] : array_values(array_filter($entries, static fn (string $entry): bool => ! str_starts_with($entry, '.')));
    }
}
