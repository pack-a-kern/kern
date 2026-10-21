<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Totp;

use OTPHP\TOTP;
use RuntimeException;

/**
 * Thin wrapper over `spomky-labs/otphp` (`BLUEPRINT.md` §4.7's MFA fallback). Every parameter
 * (digits, period) comes from `config/identity.php`, never a literal in this class, so a future
 * change is a config edit, not a code change.
 *
 * ## Why clock-skew verification is hand-rolled instead of using `TOTP::verify()`'s own `$leeway`
 *
 * `OTPHP\TOTP::verify(string $otp, ?int $timestamp, ?int $leeway)`'s `$leeway` is in **seconds**
 * and must be strictly less than the period (its own guard: `$leeway < $this->getPeriod()`) — so
 * it cannot express "tolerate a full adjacent 30-second period" (`leeway = 30` on a 30-second
 * period throws `InvalidParameterException`), only a fraction of one. `verify()` here instead
 * checks the current period plus `clock_skew_periods` whole periods on each side directly, reusing
 * the library's own constant-time comparison (`OTP::compareOTP()`, `hash_equals()` under the hood)
 * for every candidate — never comparing codes itself.
 */
final class TotpVerifier
{
    /**
     * @return non-empty-string
     */
    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $accountLabel): string
    {
        $label = $accountLabel !== '' ? $accountLabel : 'account';

        return $this->build($secret)
            ->withLabel($label)
            ->withIssuer($this->issuer())
            ->getProvisioningUri();
    }

    public function verify(string $secret, string $code, ?int $now = null): bool
    {
        return $this->matchingStep($secret, $code, $now) !== null;
    }

    /**
     * Which time step `$code` belongs to, or null if it matches none inside the skew.
     *
     * The step is what makes a code single-use: a caller that remembers the last step it accepted can
     * refuse that one and every earlier one, which a yes or no cannot express. Counted the way the
     * code itself is, the Unix time divided by the period.
     */
    public function matchingStep(string $secret, string $code, ?int $now = null): ?int
    {
        if ($code === '') {
            return null;
        }

        $totp = $this->build($secret);
        $currentTimestamp = $now ?? time();
        $currentTimestamp = $currentTimestamp >= 0 ? $currentTimestamp : 0;
        $period = $totp->getPeriod();
        $skew = $this->clockSkewPeriods();

        for ($offset = -$skew; $offset <= $skew; $offset++) {
            $candidateTimestamp = $currentTimestamp + ($offset * $period);

            if ($candidateTimestamp < 0) {
                continue;
            }

            if ($totp->verify($code, $candidateTimestamp)) {
                return intdiv($candidateTimestamp, $period);
            }
        }

        return null;
    }

    private function build(string $secret): TOTP
    {
        if ($secret === '') {
            throw new RuntimeException('A TOTP secret must not be empty.');
        }

        return TOTP::createFromSecret($secret)
            ->withPeriod($this->periodSeconds())
            ->withDigits($this->digits());
    }

    /**
     * @return non-empty-string
     */
    private function issuer(): string
    {
        // No fallback: the name an authenticator app files this account under is the application's
        // own, and a second spelling of it here is how the two come to disagree. `APP_NAME` is
        // required — compose refuses to start without it — so an empty one is a broken deployment,
        // said out loud here rather than papered over with a literal. A passkey or an authenticator
        // entry created under the wrong name is not something the person can fix afterwards.
        $issuer = config()->string('identity.totp.issuer');

        if ($issuer === '') {
            throw new RuntimeException('identity.totp.issuer is empty — set APP_NAME.');
        }

        return $issuer;
    }

    private function periodSeconds(): int
    {
        $period = config('identity.totp.period_seconds');
        $value = is_int($period) ? $period : 30;

        return $value > 0 ? $value : 30;
    }

    private function digits(): int
    {
        $digits = config('identity.totp.digits');
        $value = is_int($digits) ? $digits : 6;

        return $value > 0 ? $value : 6;
    }

    private function clockSkewPeriods(): int
    {
        $skew = config('identity.totp.clock_skew_periods');

        return is_int($skew) ? $skew : 1;
    }
}
