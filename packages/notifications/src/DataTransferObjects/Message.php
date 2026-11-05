<?php

declare(strict_types=1);

namespace Kern\Notifications\DataTransferObjects;

use InvalidArgumentException;
use Kern\Notifications\Enums\Level;

/**
 * What one notice says, in one language: the same words in the bell and on a device.
 *
 * `url` is a path inside the application — never an address elsewhere, so a notice cannot be a link
 * out of it. `tag` lets a newer notice about the same thing replace the older one on a device instead
 * of stacking beside it.
 */
final readonly class Message
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public Level $level = Level::Info,
        public ?string $tag = null,
    ) {
        // `//host` is a path to the browser and an address on another site to everyone else.
        if (! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            throw new InvalidArgumentException('A notice links to a path inside the application.');
        }
    }

    /** @return array{title: string, body: string, url: string, level: string, tag: string|null} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'level' => $this->level->value,
            'tag' => $this->tag,
        ];
    }
}
