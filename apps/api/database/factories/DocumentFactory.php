<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPart;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Document>
 *
 * A document made this way gets one part, because that is what almost every document is and what
 * almost every test means by "a document". {@link withParts()} makes the multi-file case explicit
 * where a test is about that.
 *
 * The part is created after the document, not alongside it: a part's encrypted filename is
 * encrypted with the *subject's* DEK, reached through the parent, so the parent has to exist first.
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
            'sha256' => hash('sha256', $this->faker->uuid()),
            'part_count' => 1,
            'source' => DocumentSource::USER_UPLOAD,
            'received_at' => now('UTC'),
        ];
    }

    public function configure(): static
    {
        return $this->withParts(1);
    }

    public function status(DocumentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * Overrides for the document's file — `mime_type`, `size_bytes`, the filename.
     *
     * Separate from `create()`'s own array because these describe the *file*, and the file is no
     * longer the document: passing `mime_type` to the document would be asking for a column that
     * deliberately no longer exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function part(array $attributes): static
    {
        return $this->withParts(1, $attributes);
    }

    /**
     * A document made of several files — an invoice photographed page by page.
     *
     * The plan is captured into the callback rather than kept on the factory, because a factory
     * method returns a *new* factory: a closure reading `$this->partCount` would read the instance
     * that existed when `configure()` ran, which is never the one a later `->part()` produced. That
     * is also why this replaces the callbacks rather than adding to them — otherwise every call in
     * a chain would create its own set of parts.
     *
     * @param  array<string, mixed>  $attributes  applied to every part
     */
    public function withParts(int $count, array $attributes = []): static
    {
        return $this->newInstance(['afterCreating' => new Collection()])
            ->afterCreating(function (Document $document) use ($count, $attributes): void {
                for ($position = 1; $position <= $count; $position++) {
                    $this->makePart($document, $position, $attributes);
                }

                // `sha256` is deliberately left as given. In production it is the composite of the
                // parts' hashes, computed by `InitiateDocumentUpload` and asserted there; here, a
                // test that passes a recognisable hash is asking for that hash, and a factory that
                // quietly replaced it would make the test lie about what it set up.
                $document->forceFill(['part_count' => $count])->save();

                $document->load('parts');
            });
    }

    /** @param array<string, mixed> $attributes */
    private function makePart(Document $document, int $position, array $attributes): DocumentPart
    {
        $part = DocumentPart::forDocument($document, $position);
        $part->fill([
            'original_filename_ciphertext' => 'statement-' . $this->faker->numerify('####') . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 5_000_000),
            'sha256' => hash('sha256', $this->faker->uuid()),
            'object_path' => ObjectKey::forDocumentPart(
                $document->tax_subject_id,
                $document->id,
                $part->id,
                DocumentObjectKind::ORIGINAL,
            )->value,
            'object_encryption_version' => 1,
            // Matching the document it belongs to: a factory asked for a READY document means one
            // whose files got there, not one whose files are somehow still in quarantine.
            'status' => $document->status === DocumentStatus::QUARANTINE
                ? DocumentStatus::QUARANTINE
                : DocumentStatus::SCANNED,
            ...$attributes,
        ]);
        $part->save();

        return $part;
    }
}
