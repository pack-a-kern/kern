<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Actions;

use App\Domain\TaxKnowledge\DataTransferObjects\LegislationParsingResult;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceDocumentVersionException;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser;
use App\Domain\TaxKnowledge\Services\LegislationSectionPersister;
use App\Domain\TaxKnowledge\Storage\RawSourceObjectStore;
use DateTimeImmutable;

/**
 * Structurally (re-)parses one already-ingested `ext_source_document_versions` row by id — the
 * standalone entry point for re-parsing after a parser upgrade, or for parsing a version that was
 * ingested earlier without being parsed. Reads raw bytes back from
 * {@see RawSourceObjectStore} rather than requiring a caller to still have them in memory.
 */
final readonly class ParseLegislationDocumentVersion
{
    public function __construct(
        private RawSourceObjectStore $rawStore,
        private LegislatieJustRoHtmlParser $parser,
        private LegislationSectionPersister $persister,
    ) {
    }

    public function __invoke(
        string $sourceDocumentVersionId,
        ?DateTimeImmutable $effectiveFrom = null,
        ?DateTimeImmutable $effectiveUntil = null,
    ): LegislationParsingResult {
        /** @var SourceDocumentVersion|null $version */
        $version = SourceDocumentVersion::query()->find($sourceDocumentVersionId);

        if ($version === null) {
            throw UnknownSourceDocumentVersionException::forId($sourceDocumentVersionId);
        }

        $bytes = $this->rawStore->retrieve($version->raw_object_path);
        $parsed = $this->parser->parse($bytes);

        return $this->persister->persist(
            $sourceDocumentVersionId,
            $parsed,
            LegislatieJustRoHtmlParser::VERSION,
            $effectiveFrom,
            $effectiveUntil,
        );
    }
}
