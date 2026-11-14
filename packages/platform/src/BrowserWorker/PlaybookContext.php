<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use RuntimeException;

/**
 * What the Browser card says every playbook's browser is, put into the playbook on its way out — in one
 * place, so no package wires a browser or a proxy of its own.
 *
 * - The engine and the `User-Agent`, together: the same name this installation's own requests send
 *   (ADR 0025 §3), from the engine it belongs to. A playbook that names either, or a device — whose
 *   descriptor carries both — keeps its own; one alone would contradict the other.
 * - The proxy, when it is on for this playbook's `name`. A playbook that names its own keeps it.
 */
final readonly class PlaybookContext
{
    public function __construct(
        private ConfigRepository $config,
        private ResolveBrowserProxy $proxy,
    ) {
    }

    /**
     * @param  array<string, mixed>  $playbook
     * @return array<string, mixed>
     */
    public function dress(array $playbook): array
    {
        /** @var array<string, mixed> $context */
        $context = is_array($playbook['context'] ?? null) ? $playbook['context'] : [];

        if (! isset($context['engine']) && ! isset($context['userAgent']) && ! isset($context['device'])) {
            $context['engine'] = $this->engine()->value;
            $context['userAgent'] = $this->userAgent();
        }

        if (! isset($context['proxy'])) {
            $name = $playbook['name'] ?? null;
            $proxy = is_string($name) ? ($this->proxy)()->for($name) : null;

            if ($proxy !== null) {
                $context['proxy'] = $proxy;
            }
        }

        $playbook['context'] = $context;

        return $playbook;
    }

    private function engine(): BrowserEngine
    {
        $engine = $this->config->get(SettingKey::SOURCES_BROWSER_ENGINE->configPath());
        $engine = is_string($engine) ? BrowserEngine::tryFrom($engine) : null;

        return $engine ?? throw new RuntimeException('config/sources.php must name the browser engine (SOURCES_BROWSER_ENGINE): chromium, firefox or webkit.');
    }

    private function userAgent(): string
    {
        $userAgent = $this->config->get(SettingKey::SOURCES_BROWSER_USER_AGENT->configPath());

        if (! is_string($userAgent) || ! SettingType::isHeaderValue($userAgent)) {
            throw new RuntimeException('config/sources.php must give the browser a User-Agent a header can carry (SOURCES_BROWSER_USER_AGENT).');
        }

        return $userAgent;
    }
}
