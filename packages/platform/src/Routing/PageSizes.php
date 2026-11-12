<?php

declare(strict_types=1);

namespace Kern\Platform\Routing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\Rule;
use Kern\Audit\Contracts\PageSizes as AuditPageSizes;
use RuntimeException;

/**
 * The sizes a page of any list may be, and the one it is when none is asked for: one set for every
 * endpoint and the pager that drives it (`KERN_PAGE_SIZES`, `KERN_PAGE_SIZE_DEFAULT`).
 *
 * The screen is handed the same set through the shared page props, so the choices it offers are the
 * ones the server accepts — never a list kept on each side.
 */
final readonly class PageSizes implements AuditPageSizes
{
    /**
     * @param non-empty-list<positive-int> $sizes ascending
     * @param positive-int $default one of `$sizes`
     */
    private function __construct(public array $sizes, public int $default)
    {
    }

    /** Read once, and refused whole when a value is missing or malformed. */
    public static function fromConfig(Repository $config): self
    {
        $sizes = [];

        foreach (explode(',', self::text($config->get('kern.paging.sizes'))) as $part) {
            $size = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($size === false || ($sizes !== [] && $size <= $sizes[array_key_last($sizes)])) {
                throw new RuntimeException('KERN_PAGE_SIZES must be whole numbers above zero, ascending and comma-separated (e.g. 10,25,50,100).');
            }

            $sizes[] = $size;
        }

        $default = filter_var(self::text($config->get('kern.paging.default')), FILTER_VALIDATE_INT);

        if (! is_int($default) || ! in_array($default, $sizes, true)) {
            throw new RuntimeException('KERN_PAGE_SIZE_DEFAULT must be one of KERN_PAGE_SIZES.');
        }

        return new self($sizes, $default);
    }

    public function rule(): array
    {
        return ['nullable', 'integer', Rule::in($this->sizes)];
    }

    public function of(mixed $requested): int
    {
        $size = is_numeric($requested) ? (int) $requested : null;

        return $size !== null && in_array($size, $this->sizes, true) ? $size : $this->default;
    }

    /** @return array{sizes: non-empty-list<positive-int>, default: positive-int} for the screen */
    public function toArray(): array
    {
        return ['sizes' => $this->sizes, 'default' => $this->default];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
