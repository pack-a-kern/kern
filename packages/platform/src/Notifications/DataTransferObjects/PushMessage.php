<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\DataTransferObjects;

/**
 * What one device notification says, in one language — built when it is sent and kept nowhere.
 *
 * It travels encrypted to the one device (Web Push, RFC 8291): the push service in between carries it
 * without being able to read it. `url` is a path inside the application, never an address elsewhere;
 * the service worker opens only paths of its own origin. `tag` lets a newer notice about the same thing
 * replace the older one on the device instead of stacking beside it.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $tag = null,
    ) {
    }

    /** @return array{title: string, body: string, url: string, tag: string|null} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'tag' => $this->tag];
    }
}
