<?php

declare(strict_types=1);

namespace Kern\Legislation\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Kern\Legislation\Actions\SyncKnownLegislation;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Throwable;

/**
 * Reads a law from legislatie.just.ro with all its consolidations — or, without arguments, looks for
 * new consolidations of every law already read.
 *
 * Returns as soon as the consolidations are found: reading them is queued, one job each, on the
 * `legislation` queue. Horizon shows the progress, and the legislation page the result.
 */
final class SyncLegislationCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:legislation:sync
        {type? : The act type, e.g. LEGE, HOTARARE, ORDONANTA — diacritics optional}
        {number? : The act number, e.g. 227}
        {year? : The year of the act, e.g. 2015}
        {--from= : Read consolidations from this date (YYYY-MM-DD), and the one in force on it}
        {--portal-id= : The portal\'s id for the act, e.g. 236118 — only needed when several acts share its type and number that year}';

    /** @var string */
    protected $description = 'Read a law and its consolidations from legislatie.just.ro, or update every law already read.';

    public function handle(SyncLegislationDocument $sync, SyncKnownLegislation $syncKnown): int
    {
        $type = $this->argument('type');
        $number = $this->argument('number');
        $year = $this->argument('year');
        $from = $this->option('from');
        $portalId = $this->option('portal-id');

        try {
            if ($type === null && $number === null && $year === null) {
                $started = $syncKnown();
            } elseif (! is_string($type) || ! is_string($number) || ! ctype_digit($number) || ! is_string($year) || ! ctype_digit($year)
            || ($portalId !== null && ! ctype_digit($portalId))) {
                $this->components->error('Give the act as type, number and year — e.g. LEGE 227 2015 — or nothing, to update every law already read. --portal-id is digits only.');

                return self::FAILURE;
            } else {
                $fromDate = is_string($from) && $from !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : null;

                if ($fromDate === false) {
                    $this->components->error('--from must be a date written YYYY-MM-DD.');

                    return self::FAILURE;
                }

                $started = [$sync($type, (int) $number, (int) $year, $fromDate, $portalId)];
            }
        } catch (Throwable $exception) {
            $this->components->error('Legislation sync failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($started === []) {
            $this->components->info('No law has been read yet: run this with a type, number and year first.');
        }

        foreach ($started as $law) {
            $this->components->info(sprintf(
                '%s: %d consolidations listed, %d to read — queued on "%s".',
                $law->title,
                $law->listed,
                $law->toRead,
                config()->string('legislation.queue'),
            ));
        }

        return self::SUCCESS;
    }
}
