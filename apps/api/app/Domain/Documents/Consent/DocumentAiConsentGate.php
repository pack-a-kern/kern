<?php

declare(strict_types=1);

namespace App\Domain\Documents\Consent;

use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\Models\DocumentAiConsent;

/**
 * ADR 0005's "per-subject consent, recorded, not assumed from a global setting" — the single
 * place that requirement is enforced. `App\Domain\Documents\ExternalAi\
 * RequestExternalDocumentExtraction` calls {@see self::assertConsented()} before ever
 * constructing a provider request; nothing else in this module reads or writes
 * `document_ai_consents` directly.
 */
final class DocumentAiConsentGate
{
    public function grant(string $subjectId): void
    {
        DocumentAiConsent::query()->updateOrCreate(
            ['tax_subject_id' => $subjectId],
            ['consented_at' => now('UTC'), 'revoked_at' => null],
        );
    }

    public function revoke(string $subjectId): void
    {
        $consent = DocumentAiConsent::query()->find($subjectId);

        if ($consent === null) {
            return;
        }

        $consent->revoked_at = now('UTC');
        $consent->save();
    }

    public function isConsented(string $subjectId): bool
    {
        return DocumentAiConsent::query()->find($subjectId)?->isActive() ?? false;
    }

    /**
     * Which of `$subjectIds` currently have active consent.
     *
     * Exists so a screen showing a switch per subject can ask once instead of calling
     * {@see isConsented()} in a loop. Reads still go through this gate — the model's own docblock
     * makes that a rule, so that ADR 0005's "recorded, not assumed" consent has exactly one place
     * where its meaning (`consented_at` set, `revoked_at` not) is decided.
     *
     * @param  list<string>  $subjectIds
     * @return list<string>
     */
    public function consentedAmong(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $consented = [];

        foreach (DocumentAiConsent::query()->findMany($subjectIds) as $consent) {
            // `getKey()` is `mixed` to static analysis; the model declares a string key.
            $key = $consent->getKey();

            if ($consent->isActive() && is_scalar($key)) {
                $consented[] = (string) $key;
            }
        }

        return $consented;
    }

    /** @throws AiConsentRequiredException if the subject has not granted active consent. */
    public function assertConsented(string $subjectId): void
    {
        if (! $this->isConsented($subjectId)) {
            throw AiConsentRequiredException::forSubject($subjectId);
        }
    }
}
