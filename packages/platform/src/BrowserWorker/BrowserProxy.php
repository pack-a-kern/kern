<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

/**
 * The proxy the worker's browsers go out through, and the playbooks that use it.
 *
 * Off, it carries nothing: turning it off empties the address and the credentials with it.
 */
final readonly class BrowserProxy
{
    /** @param list<string> $playbooks the playbooks' own `name`s */
    public function __construct(
        public bool $enabled,
        public ?string $server,
        public ?string $username,
        public ?string $password,
        public array $playbooks,
    ) {
    }

    /**
     * The playbook's `context.proxy`, when the proxy is on for it.
     *
     * @return array{server: string, username: string, password: string}|null
     */
    public function for(string $playbook): ?array
    {
        if (! $this->enabled || ! in_array($playbook, $this->playbooks, true)
            || $this->server === null || $this->username === null || $this->password === null) {
            return null;
        }

        return ['server' => $this->server, 'username' => $this->username, 'password' => $this->password];
    }
}
