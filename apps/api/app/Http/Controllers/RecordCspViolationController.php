<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * `POST /csp-report` — where a browser reports something the Content-Security-Policy would block
 * (`App\Http\Middleware\ContentSecurityPolicy`). Logged as `csp_violation`, so a report-only policy
 * can be read for what it would break before it is enforced.
 *
 * Without a CSRF token, because the browser sends these itself, and throttled, because anyone can.
 * What is logged is reduced to what says which rule and which source: the directive, and the blocked
 * resource, page and script as origin plus path — never a query string, which is where an identifier
 * or a token would be.
 */
final class RecordCspViolationController
{
    private const int MAX_FIELD_LENGTH = 200;

    public function __invoke(Request $request): Response
    {
        $decoded = json_decode($request->getContent(), true);
        $report = is_array($decoded) && is_array($decoded['csp-report'] ?? null) ? $decoded['csp-report'] : null;

        if ($report !== null) {
            Log::warning('csp_violation', [
                'directive' => self::field($report['effective-directive'] ?? $report['violated-directive'] ?? null),
                'blocked' => self::location($report['blocked-uri'] ?? null),
                'page' => self::location($report['document-uri'] ?? null),
                'source' => self::location($report['source-file'] ?? null),
                'line' => is_int($report['line-number'] ?? null) ? $report['line-number'] : null,
            ]);
        }

        return response()->noContent();
    }

    /** A URL as origin plus path; a keyword the browser sends instead of one (`inline`, `eval`) as is. */
    private static function location(mixed $value): ?string
    {
        $value = self::field($value);

        if ($value === null || ! str_contains($value, '://')) {
            return $value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }

    private static function field(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, self::MAX_FIELD_LENGTH) : null;
    }
}
