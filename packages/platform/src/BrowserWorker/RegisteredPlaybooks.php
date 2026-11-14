<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The playbooks the installed packages registered under `config('playbooks')`, by their own `name`.
 *
 * A package registers one either as the playbook itself or beside what it reads from it (`playbook`);
 * both are found, so none is listed by hand.
 */
final readonly class RegisteredPlaybooks
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];

        foreach ((array) $this->config->get('playbooks') as $entry) {
            $playbook = is_array($entry) && isset($entry['playbook']) && is_array($entry['playbook']) ? $entry['playbook'] : $entry;
            $name = is_array($playbook) ? ($playbook['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }
}
