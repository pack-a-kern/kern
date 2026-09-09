<?php

declare(strict_types=1);

namespace App\Support\Crypto\Casts;

use function app;

use App\Support\Crypto\AssociatedData;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use App\Support\Crypto\EnvelopeCipher;
use App\Support\Crypto\Exceptions\CastConfigurationException;
use App\Support\KeyManagement\SubjectDekService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast: `protected $casts = ['cnp' => Encrypted::class];` transparently encrypts on
 * write and decrypts on read, per BLUEPRINT.md §4.2/§4.3.
 *
 * Requires the owning model to implement `Contracts\EncryptsWithSubjectDek` (so the cast knows
 * whose DEK to use) and to have its primary key already set before this cast's `set()` runs
 * (see `Concerns\HasUuidPrimaryKey` — the AAD is `subject_id | table | field | record_uuid`,
 * and `record_uuid` must exist before the value it protects does).
 *
 * Laravel instantiates custom cast classes with `new $castType(...$args)` — it does not resolve
 * them through the container (`Illuminate\Database\Eloquent\Concerns\HasAttributes::resolveCasterClass()`),
 * so there is no constructor-injection path for `SubjectDekService`. Resolving it via the `app()`
 * helper here is a deliberate, narrow exception to "no service locators" (docs/CODE-QUALITY.md
 * §4): it is forced by Eloquent's own cast-instantiation contract, confined to this
 * persistence-layer adapter, and never reaches `Domain/TaxEngine` or any other domain core.
 *
 * @implements CastsAttributes<string, string>
 */
final class Encrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw CastConfigurationException::valueMustBeString($model::class, $key);
        }

        return EnvelopeCipher::open(
            $value,
            $this->plaintextDek($model),
            $this->aad($model, $key),
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // $value is `TSet|null` per the class-level `@implements CastsAttributes<string, string>`
        // — PHPStan/Larastan treats it as already-narrowed to `string` here, unlike `get()`'s
        // `$value` (the raw, genuinely-mixed stored column value). If a caller assigns a
        // non-string anyway (bypassing the static contract), `EnvelopeCipher::seal()`'s own
        // `string $plaintext` parameter combined with this file's `declare(strict_types=1)`
        // throws a native `TypeError` — a caller error surfaces immediately, not silently.
        return EnvelopeCipher::seal(
            $value,
            $this->plaintextDek($model),
            $this->aad($model, $key),
        );
    }

    private function plaintextDek(Model $model): string
    {
        return app(SubjectDekService::class)->plaintextDekFor(
            $this->encryptableModel($model)->encryptionSubjectId(),
        );
    }

    private function aad(Model $model, string $key): AssociatedData
    {
        $recordId = $model->getKey();

        if (! is_string($recordId) || $recordId === '') {
            throw CastConfigurationException::missingPrimaryKey($model::class);
        }

        return AssociatedData::for(
            $this->encryptableModel($model)->encryptionSubjectId(),
            $model->getTable(),
            $key,
            $recordId,
        );
    }

    private function encryptableModel(Model $model): EncryptsWithSubjectDek
    {
        if (! $model instanceof EncryptsWithSubjectDek) {
            throw CastConfigurationException::modelMustImplementContract($model::class);
        }

        return $model;
    }
}
