<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Kern\Investments\Config\BvbSource;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Models\IssuerProfile;
use Kern\Investments\Parsing\Bvb\IssuerPageParser;
use Kern\Investments\Parsing\Bvb\Meeting;
use Kern\Investments\Parsing\Bvb\Shareholder;
use Kern\Legislation\Services\SourceIngestionService;

/**
 * A company's profile — its shareholders' meetings and who holds it — read from its BVB page only when a
 * person asks (ADR 0025 §5), the page kept as evidence. Asked again while the stored one is younger than its
 * refresh age, the stored one is the answer and BVB is not asked.
 */
final readonly class IssuerProfiles
{
    public function __construct(
        private InvestmentsConfig $config,
        private SourceIngestionService $ingestion,
    ) {
    }

    public function refresh(string $key): IssuerProfile
    {
        $stored = IssuerProfile::query()->where('issuer_key', $key)->first();

        if ($stored !== null && $stored->read_at->greaterThan(now()->subHours($this->config->api->profileRefreshHours))) {
            return $stored;
        }

        $page = $this->ingestion->ingest(BvbSource::CODE, $this->config->bvb->instrumentUrl($key), 'BVB_INSTRUMENT', "BVB instrument {$key}");
        $issuer = IssuerPageParser::parse($page->rawBytes);

        return IssuerProfile::query()->updateOrCreate(['issuer_key' => $key], [
            'meetings' => array_map(static fn (Meeting $m): array => ['date' => $m->date->format('Y-m-d'), 'kind' => $m->kind], $issuer->meetings),
            'shareholders' => array_map(static fn (Shareholder $s): array => ['holder' => $s->holder, 'shares' => $s->shares, 'percent' => $s->percent], $issuer->shareholders),
            'shareholders_as_of' => $issuer->shareholdersAsOf?->format('Y-m-d'),
            'read_at' => now(),
            'evidence_id' => $page->sourceDocumentVersionId,
        ]);
    }
}
