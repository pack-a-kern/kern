<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

use Illuminate\Support\Facades\Log;

/**
 * Why a call to the configured AI provider did not work, from the settings screen's connection test or
 * its model list.
 *
 * Written to the log and never to the response. Whoever presses those buttons chooses the address
 * being called, so a reason on the screen — or a code standing for one, which two tries would decode —
 * would say whether anything is listening at it, and the buttons would become a way to map addresses
 * the server can reach and the browser cannot. The screen says only that it did not connect; this is
 * for whoever reads the log.
 */
enum AiProviderFailure: string
{
    case UNREACHABLE = 'unreachable';
    case REJECTED_CREDENTIALS = 'rejected_credentials';
    case PROVIDER_ERROR = 'provider_error';
    case INVALID_RESPONSE = 'invalid_response';
    case NO_MODELS = 'no_models';
    /** The provider answered with its models, and the one configured is not among them. */
    case MODEL_NOT_SERVED = 'model_not_served';

    /** The address is not logged: it is in the settings, and the log is not the place for it twice. */
    public function log(string $operation, ?int $httpStatus = null): void
    {
        Log::warning('ai_provider_call_failed', array_filter([
            'operation' => $operation,
            'reason' => $this->value,
            'http_status' => $httpStatus,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
