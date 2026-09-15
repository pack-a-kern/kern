<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Enums;

/**
 * `ext_source_document_versions.status` — set by
 * `App\Domain\TaxKnowledge\Services\LegislationSectionPersister` after structural parsing, never
 * by the fetch/ingestion step itself (a version starts `PENDING` and stays that way until
 * something actually parses it — Milestone 2's `Domain/Fx` never parses a version at all, so its
 * BNR XML versions remain `PENDING` forever, correctly).
 */
enum SourceDocumentVersionStatus: string
{
    /** Fetched and stored, not yet structurally parsed. The default for every new version. */
    case PENDING = 'PENDING';

    /** Parsed into `ext_source_sections`, and this version's `effective_from` is known. */
    case PARSED = 'PARSED';

    /**
     * Parsed into `ext_source_sections`, but this version's effective date range could not be
     * determined from the document itself (`docs/AGENTS.md` §2 — never infer or guess a date).
     * A human must supply `effective_from`/`effective_until` before this version can participate
     * in a temporal query (`App\Domain\TaxKnowledge\Services\LegislationTemporalQuery`).
     */
    case REQUIRES_REVIEW = 'REQUIRES_REVIEW';
}
