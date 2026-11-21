<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Kern\Investments\Models\IssuerProfile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `GET /api/v1/investments/issuers/{key}/logo` — the company logo cached from this issuer's BVB page,
 * served from local disk (`issuer_logos`); 404 when the issuer has no logo on file. The bytes never
 * change for a given hash, so the response is cached hard and carries the stored hash as its ETag, which
 * makes a repeat view a 304. Never reaches out — the logo is only ever what a profile refresh already
 * stored.
 */
final class ShowIssuerLogoController
{
    public function __invoke(Request $request, string $key): Response|BinaryFileResponse
    {
        $profile = IssuerProfile::query()->where('issuer_key', $key)->first();
        $disk = Storage::disk('issuer_logos');

        if ($profile?->logo_path === null || ! $disk->exists($profile->logo_path)) {
            return response('', Response::HTTP_NOT_FOUND);
        }

        $etag = '"' . $profile->logo_etag . '"';

        // Browser already holds this exact image: answer 304 without sending the bytes again.
        if ($profile->logo_etag !== null && trim($request->headers->get('If-None-Match') ?? '') === $etag) {
            return response('', Response::HTTP_NOT_MODIFIED)->header('ETag', $etag)->header('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return response()->file($disk->path($profile->logo_path), [
            'Content-Type' => $profile->logo_mime ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
        ]);
    }
}
