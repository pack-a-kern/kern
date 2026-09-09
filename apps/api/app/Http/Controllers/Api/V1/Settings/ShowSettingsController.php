<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\Enums\SettingGroup;
use App\Domain\Settings\Services\ResolveSettings;
use Illuminate\Http\JsonResponse;

/**
 * Every system setting, grouped, with its effective value and where that value came from.
 *
 * Secrets are never included — `ResolveSettings` returns `has_value` and a last-four `hint` in
 * their place (`docs/handoff/041-settings-module.md`).
 */
final readonly class ShowSettingsController
{
    public function __construct(private ResolveSettings $resolver)
    {
    }

    public function __invoke(): JsonResponse
    {
        $groups = [];

        foreach ($this->resolver->all() as $group => $settings) {
            $groups[] = [
                'group' => $group,
                'settings' => array_map(
                    static fn ($setting): array => $setting->toArray(),
                    $settings,
                ),
            ];
        }

        return new JsonResponse([
            'groups' => $groups,
            'known_groups' => array_map(
                static fn (SettingGroup $g): string => $g->value,
                SettingGroup::cases(),
            ),
        ]);
    }
}
