<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Scanning\ScanOutcome;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One virus-scan attempt against one document — the evidence that a scan happened, separate from
 * the *effect* of its verdict on `documents.status`.
 *
 * Append-only by discipline rather than by constraint: nothing in this codebase updates a scan row,
 * and a re-scan writes a new one. A row that could be edited would answer "what do we believe now"
 * instead of "what did the scanner say then", which is the only question worth keeping it for.
 *
 * Carries no `tax_subject_id`, like `DocumentExtraction`: it is reached only through a `Document`,
 * which is tenant-scoped, so the boundary lives one table up and cannot drift out of agreement with
 * itself. Never holds a malware signature name — see the migration for why.
 *
 * @property string $id
 * @property string $document_id
 * @property ScanOutcome $outcome
 * @property string $scanner
 * @property string|null $scanner_version
 * @property string|null $definitions_version
 * @property int|null $duration_ms
 * @property \Illuminate\Support\Carbon $scanned_at
 */
final class DocumentScan extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'outcome',
        'scanner',
        'scanner_version',
        'definitions_version',
        'duration_ms',
        'scanned_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'outcome' => ScanOutcome::class,
        'duration_ms' => 'integer',
        'scanned_at' => 'datetime',
    ];
}
