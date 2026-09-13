<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingKey;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Repositories\SettingsRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Whether a stranger may create an account right now.
 *
 * One answer, two callers: `RegistrationController` refuses the request, and the Inertia share
 * hides the affordance. Asking in two places would let the button and the endpoint disagree, and
 * the disagreement a person meets is the wrong one — a form that submits into a 403.
 *
 * ## The empty instance always says yes
 *
 * A deployment with no users has to let its first owner in, and this application has no password
 * reset and no console account maker to get them in another way. Turning the switch off before any
 * account exists would therefore lock the instance permanently — a setting that can brick a
 * deployment is a bug, not a policy. So the count is part of the question, not a special case
 * bolted onto the answer, and the escape hatch closes by itself the moment the first user exists.
 */
final readonly class RegistrationPolicy
{
    public function __construct(
        private SettingsRepository $settings,
        private ConfigRepository $config,
    ) {
    }

    public function isOpen(): bool
    {
        if ($this->enabled()) {
            return true;
        }

        return ! User::query()->exists();
    }

    /**
     * The configured switch, with config as the fallback for an instance where nobody has saved a
     * value yet. Decoded through the type that owns the format rather than compared to a literal
     * here — the stored form is `'1'`/`'0'` from one writer and `'true'`/`'false'` from another.
     */
    private function enabled(): bool
    {
        return SettingType::decodeBoolean($this->settings->get(SettingKey::IDENTITY_REGISTRATION_ENABLED))
            ?? (bool) $this->config->get('identity.registration.enabled', true);
    }
}
