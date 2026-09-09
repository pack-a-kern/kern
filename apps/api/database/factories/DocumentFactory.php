<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 *
 * Unlike `TaxSubjectFactory`/`UserFactory`, `definition()` sets `original_filename_ciphertext`
 * directly — a `Document`'s `encryptionSubjectId()` is its `tax_subject_id` foreign key, not its
 * own id, so the owning `TaxSubject`'s DEK already exists (created when `TaxSubjectFactory` ran)
 * by the time this row is inserted. No two-phase create needed (see `Document`'s own docblock).
 */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_subject_id' => TaxSubject::factory(),
            'type' => DocumentType::OTHER,
            'status' => DocumentStatus::QUARANTINE,
            'original_filename_ciphertext' => 'statement-' . $this->faker->numerify('####') . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 5_000_000),
            'sha256' => hash('sha256', $this->faker->uuid()),
            'object_path' => '',
            'object_encryption_version' => 1,
            'source' => DocumentSource::USER_UPLOAD,
            'received_at' => now('UTC'),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Document $document): void {
            $document->object_path = ObjectKey::forDocument(
                $document->tax_subject_id,
                $document->id,
                DocumentObjectKind::ORIGINAL,
            )->value;
        });
    }

    public function status(DocumentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
