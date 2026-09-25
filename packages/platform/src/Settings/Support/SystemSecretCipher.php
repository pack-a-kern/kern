<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Support;

use Closure;
use Kern\Platform\Crypto\AssociatedData;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\KeyManagement\SubjectDekService;

/**
 * Encrypts/decrypts secret setting values under the system-wide DEK (`SystemEncryptionScope`),
 * reusing `Kern\Platform\Crypto\EnvelopeCipher`/`AssociatedData` exactly as every tenant-scoped
 * encrypted field does — only the DEK source and the AAD's leading segment differ.
 *
 * Not implemented as an `Kern\Platform\Crypto\Casts\Encrypted` Eloquent cast: that cast requires
 * the owning model to have a UUID primary key assigned before construction
 * (`Concerns\HasUuidPrimaryKey`) and to implement `Contracts\EncryptsWithSubjectDek`. A `settings`
 * row's primary key is the setting's own name (e.g. `"ai.api_key"`), not a generated UUID, and
 * `Kern\Platform\Settings\Repositories\SettingsRepository` deliberately uses the plain query builder
 * rather than an Eloquent model (the same choice `Kern\TaxRomania\Subjects\Infrastructure\
 * EloquentSubjectDekRepository` makes, for the same reason: this is a small, closed-shape
 * infrastructure table, not a domain entity). A dedicated cipher class calling `EnvelopeCipher`
 * directly is the simpler, equally-typed answer (`docs/CODE-QUALITY.md` §1 — no cast machinery
 * built just to avoid two explicit method calls).
 *
 * Takes a **lazy factory** for its `SubjectDekService`, not an already-built instance. This
 * matters: `Kern\Platform\Settings\Repositories\SettingsRepository` needs a `SystemSecretCipher` for
 * *every* read/write, including the overwhelming majority that touch a non-secret key
 * (`ai.provider`/`ai.base_url`/`ai.model`/`ai.enabled`) and therefore never call `seal()`/`open()`
 * at all. Building a `SubjectDekService` eagerly resolves `Kern\Platform\KeyManagement\Contracts\
 * KeyManagementService`, whose container binding (`Kern\Platform\KeyManagement\
 * KeyManagementServiceProvider`) eagerly validates every `crypto.kms.openbao.*` config value —
 * a real regression found by running this module's own test suite: every caller of
 * `RequestExternalDocumentExtraction` started failing with "Missing or invalid config value
 * crypto.kms.openbao.token" purely from resolving the settings module, never from touching a
 * secret. Deferring construction until `key()` is actually called (i.e., until a secret setting
 * is actually read or written) fixes this without weakening the crypto guarantee at all — nothing
 * about *when* the KMS is reached changes what it is reached with.
 */
final readonly class SystemSecretCipher
{
    /** @param Closure(): SubjectDekService $systemDekFactory */
    public function __construct(private Closure $systemDekFactory)
    {
    }

    public function seal(string $settingKey, string $plaintext): string
    {
        return EnvelopeCipher::seal($plaintext, $this->key(), $this->aad($settingKey));
    }

    /** @throws \Kern\Platform\Crypto\Exceptions\EnvelopeException */
    public function open(string $settingKey, string $envelope): string
    {
        return EnvelopeCipher::open($envelope, $this->key(), $this->aad($settingKey));
    }

    private function key(): string
    {
        return ($this->systemDekFactory)()->plaintextDekFor(SystemEncryptionScope::ID);
    }

    /**
     * `table` is `'settings'` and `field` is `'value'` for every setting — the fourth AAD segment
     * (`record_uuid`) is what actually distinguishes one setting from another, exactly as a real
     * row's primary key would. `$settingKey` (e.g. `"ai.api_key"`) is a system-controlled,
     * closed-enum identifier (`Kern\Platform\Settings\Enums\SettingKey`), never free-form user
     * input — the same "safe to join unescaped" precondition `AssociatedData`'s own docblock
     * requires.
     */
    private function aad(string $settingKey): AssociatedData
    {
        return AssociatedData::for(SystemEncryptionScope::ID, 'settings', 'value', $settingKey);
    }
}
