<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Enums\ExtractorType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;

/**
 * Turns a parser's reading of a document into a `PROPOSED` extraction — the same row an AI provider
 * would produce, reviewed the same way.
 *
 * That sameness is the point. The review screen, the accept/correct actions, the provenance link a
 * transaction keeps, and the audit entries all work on `document_extractions`; a parser plugs into
 * that rather than beside it, so wiring an AI provider later adds a *source* of proposals and
 * changes nothing downstream.
 *
 * **Nothing is auto-confirmed.** The row lands `PROPOSED` exactly like a model's would, however
 * confident the parser was. A deterministic reading is reproducible, not infallible — a receipt can
 * be creased, a total can be misprinted — and the human step is what turns a reading into a figure
 * someone stands behind.
 *
 * The parsed values are encrypted at rest by `DocumentExtraction`'s own cast, so this writes them
 * as plain PHP and never touches the ciphertext.
 */
final readonly class ProposeParsedExtraction
{
    public function __construct(
        private ParserRegistry $parsers,
        private AuditChainService $audit,
    ) {
    }

    /**
     * @return DocumentExtraction|null  null when no parser recognised the document
     */
    public function __invoke(Document $document, string $text): ?DocumentExtraction
    {
        $result = $this->parsers->parse($text, $document->type);

        if (! $result->matched) {
            return null;
        }

        $schema = ExtractionSchema::for($document->type);

        if ($schema === null) {
            return null;
        }

        // Anything the schema does not name is dropped: a key nobody downstream understands would
        // appear on the review screen as though it meant something.
        $fields = $schema->keepKnown($result->toFieldMap());

        // Through the model's own factory rather than a hand-built row: it is the one place that
        // sets `PROPOSED` (deliberately not fillable, so mass assignment can never confirm an
        // extraction) and binds the document's subject for the encryption key and AAD.
        $extraction = DocumentExtraction::propose(
            document: $document,
            extractorType: ExtractorType::PATTERN_PARSER,
            // Which parser, and which version of it. A figure produced by an older reading must be
            // tellable from a newer one — the same reason `TaxRule` carries a version.
            extractorVersion: $result->parserName . '@' . $result->parserVersion,
            // No model was involved, and saying so is the point of recording these at all.
            modelProvider: null,
            modelName: null,
            schemaVersion: '1',
            result: $fields,
            // A decimal string, never a PHP float.
            confidenceDecimal: number_format($result->confidence(), 4, '.', ''),
        );

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $document->tax_subject_id,
            // No person read this document; a parser did.
            actorId: null,
            eventType: 'DocumentExtractionProposed',
            resourceType: 'DocumentExtraction',
            resourceId: $extraction->id,
            payloadHash: $this->audit->hashPayload([
                // The field *names* and the parser, never the values: those are encrypted at rest
                // and an audit payload is not the place to undo that.
                'document_id' => $document->id,
                'parser' => $result->parserName,
                'parser_version' => $result->parserVersion,
                'fields' => implode(',', array_keys($fields)),
            ]),
        ));

        return $extraction;
    }
}
