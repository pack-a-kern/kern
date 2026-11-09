<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Kern\Documents\DataTransferObjects\IncomingFile;
use Kern\Documents\Exceptions\DuplicateDocumentException;
use Kern\Documents\FileSecurity\Exceptions\FileSecurityException;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Rules\StreamSource;
use Kern\TaxRomania\StatementImport\Actions\StartStatementImport;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use RuntimeException;

/**
 * `POST .../wallet/imports` — upload a bank statement and start importing it.
 *
 * Answers 202 rather than 200: the file is scanned and read in the background, and the screen polls
 * the import until it is ready to have its columns confirmed. The same shape the document upload
 * uses, for the same reason — a virus scan is not something to hold a request open for.
 */
final class StartStatementImportController
{
    public function __invoke(
        Request $request,
        string $subject,
        StartStatementImport $start,
        TenantContext $tenant,
        ConfigRepository $config,
    ): JsonResponse {
        $validated = $request->validate([
            // Refused before the file is scanned, encrypted and stored: one that could never be read
            // should cost nothing but the message saying so. The reader has its own ceilings on rows
            // and pages, which decide whether a file that fits here can actually be read.
            'file' => ['required', 'file', 'max:' . (int) ($config->integer('statement_import.limits.max_file_bytes') / 1024)],
            // A source that receives transactions: an investments account is filled by its holdings.
            'account_id' => ['required', 'uuid', new StreamSource()],
        ]);

        // Tenant-scoped: an account belonging to another subject is not found rather than imported into.
        FinancialAccount::query()->findOrFail((string) $validated['account_id']);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The uploaded file has no readable temporary path.');
        }

        try {
            $import = $start(
                $tenant->currentTenantId(),
                (string) $validated['account_id'],
                new IncomingFile($path, $file->getClientOriginalName()),
                $this->actorId($request),
            );
        } catch (FileSecurityException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason()->value,
            ], 422);
        } catch (DuplicateDocumentException $exception) {
            // The same statement, already uploaded. Refused here rather than imported twice — the
            // fingerprints would stop the rows, but a second copy of the file helps nobody.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'DUPLICATE_DOCUMENT',
                'existing_document_id' => $exception->existingDocumentId,
            ], 409);
        }

        return response()->json(['id' => $import->id, 'status' => $import->status->value], 202);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
