<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Banks;

use Illuminate\Http\Response;
use Kern\TaxRomania\Banks\Logos\BankLogos;
use Kern\TaxRomania\Banks\Models\Bank;

/**
 * `GET /api/v1/banks/{bank}/logo` — a bank's logo, from this installation's own logo folder.
 *
 * The file is found through the folder's index, by the bank — never from anything in the request
 * that names a file — and its bytes are checked again on the way out (`BankLogos::contents()`).
 *
 * **Served so an SVG can do nothing but be drawn.** `Content-Security-Policy: default-src 'none'`
 * with `sandbox`, so the file opened on its own runs no script and loads nothing; `nosniff`, so the
 * type stated is the type used; and the screen shows logos through `<img>`, where an SVG never runs
 * script in the first place.
 *
 * **Cached for good**, because the address carries the file's fingerprint: a replaced file is a new
 * address. Private — the bank list is a signed-in screen's.
 */
final class ShowBankLogoController
{
    public function __invoke(string $bank, BankLogos $logos): Response
    {
        $row = Bank::query()->find($bank, ['id', 'country_code', 'bic', 'lei', 'parent_lei']);
        $logo = $row === null ? null : $logos->for($row->country_code, $row->bic, $row->lei, $row->parent_lei);
        $contents = $logo === null ? null : $logos->contents($logo);

        if ($logo === null || $contents === null) {
            abort(404);
        }

        return new Response($contents, 200, [
            'Content-Type' => $logo->mimeType,
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => '"' . $logo->fingerprint . '"',
        ]);
    }
}
