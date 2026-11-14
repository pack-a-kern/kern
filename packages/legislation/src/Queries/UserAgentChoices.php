<?php

declare(strict_types=1);

namespace Kern\Legislation\Queries;

use Kern\Legislation\Enums\UserAgentSystem;
use Kern\Legislation\Models\UserAgent;
use Kern\Platform\BrowserWorker\BrowserEngine;

/**
 * The names the Browser card offers, by engine and then by system — the order of the engines and the
 * systems as they are declared, and within a system each browser newest first.
 */
final class UserAgentChoices
{
    /** @return array{publishedAt: ?string, engines: list<array{engine: string, systems: list<array{system: string, agents: list<array{userAgent: string, browser: string, version: int}>}>}>} */
    public function __invoke(): array
    {
        $agents = UserAgent::query()->orderBy('browser')->orderByDesc('version')->orderBy('user_agent')->get();
        $engines = [];

        foreach (BrowserEngine::cases() as $engine) {
            $systems = [];

            foreach (UserAgentSystem::cases() as $system) {
                $named = array_values(array_map(
                    static fn (UserAgent $agent): array => ['userAgent' => $agent->user_agent, 'browser' => $agent->browser->value, 'version' => $agent->version],
                    $agents->filter(static fn (UserAgent $agent): bool => $agent->engine === $engine && $agent->system === $system)->all(),
                ));

                if ($named !== []) {
                    $systems[] = ['system' => $system->value, 'agents' => $named];
                }
            }

            $engines[] = ['engine' => $engine->value, 'systems' => $systems];
        }

        // One list at a time is kept, so every row carries its date.
        return ['publishedAt' => $agents->first()?->published_at->toIso8601String(), 'engines' => $engines];
    }

    /** The engine a listed name belongs to, or null for one the list does not hold. */
    public function engineOf(string $userAgent): ?BrowserEngine
    {
        return UserAgent::query()->where('user_agent', $userAgent)->first()?->engine;
    }
}
