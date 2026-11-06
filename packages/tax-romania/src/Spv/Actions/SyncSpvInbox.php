<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\TaxRomania\Spv\DataTransferObjects\SpvInboxMessage;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvSyncRunStatus;
use Kern\TaxRomania\Spv\Events\SpvSyncCompleted;
use Kern\TaxRomania\Spv\Events\SpvSyncStarted;
use Kern\TaxRomania\Spv\Exceptions\SpvSignInRequiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvSyncUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvMessage;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;
use Kern\TaxRomania\Spv\Transport\AnafCertificateApiTransport;
use Kern\TaxRomania\Spv\Transport\BrowserPortalInboxSyncTransport;

/**
 * The transport-agnostic entry point `docs/reference/anaf-spv-integration.md`'s "real
 * optimisation" describes: picks `Kern\TaxRomania\Spv\Contracts\SpvInboxSyncTransport` by
 * `SpvConnection::$auth_method` — a `CERTIFICATE` connection always uses ANAF's documented
 * `listaMesaje` API, a `USER_PASSWORD_OTP` connection always uses the (today unimplemented)
 * browser transport. Never infers or falls back from one to the other.
 *
 * Both concrete transports are injected directly, not through the shared
 * `SpvInboxSyncTransport` interface — there is no single "the" implementation to bind in a
 * container; the choice is a runtime decision this class itself makes, not a compile-time one.
 */
final readonly class SyncSpvInbox
{
    /** Messages looked up per query: a long inbox costs a few lookups, and holds one batch at a time. */
    private const int LOOKUP_BATCH = 200;

    public function __construct(
        private AnafCertificateApiTransport $certificateTransport,
        private BrowserPortalInboxSyncTransport $browserTransport,
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(string $connectionId, ?string $actorId): SpvSyncRun
    {
        /** @var SpvConnection $connection */
        $connection = SpvConnection::query()->findOrFail($connectionId);

        $run = SpvSyncRun::create([
            'tax_subject_id' => $connection->tax_subject_id,
            'connection_id' => $connection->id,
            'status' => SpvSyncRunStatus::RUNNING,
            'started_at' => now('UTC'),
        ]);

        $this->record($run, $actorId, 'SpvSyncStarted', []);
        SpvSyncStarted::dispatch($run->id, $run->tax_subject_id);

        $transport = match ($connection->auth_method) {
            SpvAuthMethod::CERTIFICATE => $this->certificateTransport,
            SpvAuthMethod::USER_PASSWORD_OTP => $this->browserTransport,
        };

        try {
            $messages = $transport->fetchMessages($connection);
        } catch (SpvSyncUnavailableException) {
            return $this->finish($run, $actorId, SpvSyncRunStatus::FAILED, 0, ['error' => 'unavailable']);
        } catch (SpvSignInRequiredException $exception) {
            /*
             * Recorded as a failed run *and* raised.
             *
             * Recorded because the attempt happened and the history should say why it stopped —
             * "nobody was signed in" is a different fact from "the worker was down", and a run that
             * silently did not exist would make the second look like the only way this fails.
             *
             * Raised because the person has to do something about it, and a 200 with a run id would tell
             * them nothing. The two are not alternatives: the ledger and the answer serve different
             * readers.
             */
            $this->finish($run, $actorId, SpvSyncRunStatus::FAILED, 0, ['error' => 'sign_in_required']);

            throw $exception;
        }

        // Looked up a batch at a time rather than one query per message; one batch's rows held, not the inbox's.
        foreach (array_chunk($messages, self::LOOKUP_BATCH) as $batch) {
            $this->upsertMessages($connection->tax_subject_id, $batch);
        }

        return $this->finish($run, $actorId, SpvSyncRunStatus::SUCCEEDED, count($messages), ['message_count' => count($messages)]);
    }

    /** @param array<string, mixed> $summary */
    private function finish(SpvSyncRun $run, ?string $actorId, SpvSyncRunStatus $status, int $messageCount, array $summary): SpvSyncRun
    {
        $run->status = $status;
        $run->completed_at = now('UTC');
        $run->summary_json = $summary;
        $run->save();

        $this->record($run, $actorId, 'SpvSyncCompleted', ['status' => $status->value]);
        SpvSyncCompleted::dispatch($run->id, $run->tax_subject_id, $status, $messageCount);

        return $run;
    }

    /** @param list<SpvInboxMessage> $messages */
    private function upsertMessages(string $taxSubjectId, array $messages): void
    {
        $blindIndexes = array_map(
            fn (SpvInboxMessage $message): string => $this->blindIndex->compute(Normalizer::text($message->externalId)),
            $messages,
        );

        /** @var array<string, SpvMessage> $existing by blind index */
        $existing = SpvMessage::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->whereIn('external_id_blind_index', array_values(array_unique($blindIndexes)))
            ->get()
            ->keyBy('external_id_blind_index')
            ->all();

        foreach ($messages as $position => $message) {
            $externalIdBlindIndex = $blindIndexes[$position];

            $attributes = [
                'tax_subject_id' => $taxSubjectId,
                'external_id_ciphertext' => $message->externalId,
                'external_id_blind_index' => $externalIdBlindIndex,
                'title_ciphertext' => $message->title,
                'received_at' => $message->receivedAt,
                'metadata_ciphertext' => $message->metadata === [] ? null : json_encode($message->metadata, JSON_THROW_ON_ERROR),
            ];

            if (isset($existing[$externalIdBlindIndex])) {
                $existing[$externalIdBlindIndex]->fill($attributes)->save();

                continue;
            }

            // Kept, so a message the inbox lists twice updates the row its first listing made, as it always has.
            $existing[$externalIdBlindIndex] = SpvMessage::create($attributes);
        }
    }

    /** @param array<string, mixed> $payload */
    private function record(SpvSyncRun $run, ?string $actorId, string $eventType, array $payload): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $run->tax_subject_id,
            actorId: $actorId,
            eventType: $eventType,
            resourceType: 'SpvSyncRun',
            resourceId: $run->id,
            payloadHash: $this->audit->hashPayload($payload),
        ));
    }
}
