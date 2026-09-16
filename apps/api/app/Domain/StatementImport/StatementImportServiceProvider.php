<?php

declare(strict_types=1);

namespace App\Domain\StatementImport;

use App\Domain\StatementImport\Gateways\HttpStatementParseGateway;
use App\Domain\StatementImport\Gateways\StatementParseGateway;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the statement reader, the one seam in this module that leaves the process.
 *
 * Bound from configuration rather than constructed at the call site for the same reason the document
 * text extractor is: the address, the timeout and the shared secret are deployment facts, and a test
 * substitutes the interface instead of standing the service up.
 */
final class StatementImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StatementParseGateway::class, static function (Application $app): StatementParseGateway {
            $config = $app->make(ConfigRepository::class);

            return new HttpStatementParseGateway(
                $app->make(HttpFactory::class),
                $config->string('statement_import.parser.base_url'),
                $config->integer('statement_import.parser.timeout_seconds'),
                $config->string('statement_import.parser.auth_token'),
            );
        });
    }
}
