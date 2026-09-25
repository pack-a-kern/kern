<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST`/`DELETE /api/v1/subjects/{subject}/document-ai-consent` — ADR 0005's "per-subject
 * consent, recorded, not assumed from a global setting." Grants/revokes the one thing
 * `App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction` checks before any external
 * send is even attempted.
 */
final class DocumentAiConsentController
{
    public function store(Request $request, string $subject, DocumentAiConsentGate $gate): JsonResponse
    {
        $gate->grant($subject, $request->user()?->id);

        return response()->json(['consented' => true]);
    }

    public function destroy(Request $request, string $subject, DocumentAiConsentGate $gate): JsonResponse
    {
        $gate->revoke($subject, $request->user()?->id);

        return response()->json(['consented' => false]);
    }
}
