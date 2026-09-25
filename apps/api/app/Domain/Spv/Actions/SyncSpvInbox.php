<?php

declare(strict_types=1);

namespace App\Domain\Spv\Actions;

use App\Domain\Spv\DataTransferObjects\SpvInboxMessage;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvSyncRunStatus;
use App\Domain\Spv\Events\SpvSyncCompleted;
use App\Domain\Spv\Events\SpvSyncStarted;
use App\Domain\Spv\Exceptions\SpvSyncUnavailableException;
use App\Domain\Spv\Models\SpvConnection;
use App\Domain\Spv\Models\SpvMessage;
use App\Domain\Spv\Models\SpvSyncRun;
use App\Domain\Spv\Transport\AnafCertificateApiTransport;
use App\Domain\Spv\Transport\BrowserPortalInboxSyncTransport;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * The transport-agnostic entry point `docs/reference/anaf-spv-integration.md`'s "real
 * optimisation" describes: picks `App\Domain\Spv\Contracts\SpvInboxSyncTransport` by
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
        }

        foreach ($messages as $message) {
            $this->upsertMessage($connection->tax_subject_id, $message);
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

    private function upsertMessage(string $taxSubjectId, SpvInboxMessage $message): void
    {
        $externalIdBlindIndex = $this->blindIndex->compute(Normalizer::text($message->externalId));

        $existing = SpvMessage::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('external_id_blind_index', $externalIdBlindIndex)
            ->first();

        $attributes = [
            'tax_subject_id' => $taxSubjectId,
            'external_id_ciphertext' => $message->externalId,
            'external_id_blind_index' => $externalIdBlindIndex,
            'title_ciphertext' => $message->title,
            'received_at' => $message->receivedAt,
            'metadata_ciphertext' => $message->metadata === [] ? null : json_encode($message->metadata, JSON_THROW_ON_ERROR),
        ];

        if ($existing instanceof SpvMessage) {
            $existing->fill($attributes);
            $existing->save();

            return;
        }

        SpvMessage::create($attributes);
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
