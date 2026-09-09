<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support\RecoveryCodes;

/**
 * Generates the plaintext recovery codes shown to a user exactly once at MFA enrollment/
 * regeneration time (`BLUEPRINT.md` §4.7). Never persists anything itself — the caller
 * (`App\Domain\Identity\Actions\GenerateRecoveryCodes`) hashes each returned string with
 * `Illuminate\Support\Facades\Hash` before storing it, per the `user_recovery_codes` migration's
 * docblock.
 *
 * Codes are hex, not digits-only — a 10-byte (80-bit) value hex-encoded and grouped for
 * readability (`XXXXX-XXXXX`), which is unambiguous to read aloud/copy and cannot be confused with
 * a 6-digit TOTP code (`App\Domain\Identity\Actions\CompleteMfaChallenge` tries both against the
 * same input safely regardless, but a visually distinct shape avoids user confusion too).
 */
final class RecoveryCodeGenerator
{
    /**
     * @return list<string>
     */
    public function generate(): array
    {
        $count = $this->count();

        return array_map(fn (): string => $this->generateOne(), range(1, $count));
    }

    private function generateOne(): string
    {
        $hex = strtoupper(bin2hex(random_bytes($this->entropyBytes())));

        /** @var list<string> $groups */
        $groups = str_split($hex, 5);

        return implode('-', $groups);
    }

    private function count(): int
    {
        $count = config('identity.recovery_codes.count');

        return is_int($count) ? $count : 10;
    }

    /**
     * @return positive-int
     */
    private function entropyBytes(): int
    {
        $bytes = config('identity.recovery_codes.entropy_bytes');
        $value = is_int($bytes) ? $bytes : 10;

        return $value > 0 ? $value : 10;
    }
}
