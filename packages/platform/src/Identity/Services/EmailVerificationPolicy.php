<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * Whether an account has to prove it can read its own address before it may use the application.
 *
 * One answer, three callers: the middleware holds an unverified account at the verification screen,
 * the verification screen itself needs to know whether it is compulsory or merely offered, and the
 * settings screen shows the switch. Asking in three places would let them disagree, and the
 * disagreement a person meets is the wrong one.
 *
 * ## No escape hatch here, unlike `RegistrationPolicy`
 *
 * That one answers yes on an instance with no users, because a switch that can lock a deployment out
 * of itself is a bug. This one cannot do the same thing: "there are no verified users yet" is the
 * normal state the moment the switch is turned on, so treating it as an emergency would mean the
 * setting never takes effect at all.
 *
 * The lockout is real, then, and deliberate — there is no backfill, and an address being typed at
 * registration is not evidence anybody could read it. What answers the emergency instead is
 * `Console\Commands\Identity\VerifyUserEmailCommand`, out of band, for the case where mail is
 * misconfigured while this is on.
 */
final readonly class EmailVerificationPolicy
{
    public function __construct(
        private SettingsRepository $settings,
        private ConfigRepository $config,
    ) {
    }

    public function isRequired(): bool
    {
        return SettingType::decodeBoolean($this->settings->get(SettingKey::IDENTITY_EMAIL_VERIFICATION_REQUIRED))
            ?? (bool) $this->config->get('identity.verification.required', false);
    }
}
