<?php

declare(strict_types=1);

namespace Kern\Platform\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Laravel "tap" class (see `config/logging.php`'s `'tap' => [self::class]` on every non-`null`
 * channel): pushes {@see SensitiveDataProcessor} onto the channel's underlying Monolog logger.
 *
 * `tap` — not a channel's `processors` config key — because `processors` is only read by
 * `LogManager::createMonologDriver()` (the `monolog` driver), while `tap` runs uniformly for every
 * driver (`single`, `daily`, `stderr`, ...) via `LogManager::get()`. See
 * {@see SensitiveDataProcessor}'s own docblock for why processor *order* also matters here.
 *
 * The processor is handed the container's one {@see Redactions}, so a package that declares an
 * identifier of its own reaches every channel — including channels resolved before that package's
 * provider booted, because the processor reads the registry per record rather than copying it.
 *
 * `Illuminate\Log\Logger::pushProcessor()` only exists via `__call()` magic-proxying to the
 * wrapped Monolog logger, which static analysis cannot see through — so this goes via
 * `getLogger()` (typed `Psr\Log\LoggerInterface`) and narrows with `instanceof` instead of relying
 * on the magic method. Every channel this project configures is Monolog-backed; the `instanceof`
 * guard simply means an exotic PSR-3-only logger would skip redaction instead of fataling.
 */
final class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $underlying = $logger->getLogger();

        if ($underlying instanceof MonologLogger) {
            $underlying->pushProcessor(new SensitiveDataProcessor(app(Redactions::class)));
        }
    }
}
