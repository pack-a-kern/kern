<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Listeners;

use App\Domain\FiscalParameters\Jobs\ReadMinimumWages;
use Kern\Legislation\Events\LawConsolidationsRead;
use Kern\Legislation\Parsing\SectionText;

/**
 * A government decision setting the minimum wage was just read: propose its value.
 *
 * Which law that is, is a fiscal question — `fiscal_parameters.minimum_wage` names the document type
 * and the words in its title — so it is asked here rather than by the sync that finished. The sync
 * only says which law it read.
 */
final readonly class ReadWagesFromANewLaw
{
    public function handle(LawConsolidationsRead $event): void
    {
        $isMinimumWageDecision = $event->documentType === config()->string('fiscal_parameters.minimum_wage.document_type')
            && str_contains(
                SectionText::searchable($event->title),
                config()->string('fiscal_parameters.minimum_wage.title'),
            );

        if ($isMinimumWageDecision) {
            ReadMinimumWages::dispatch();
        }
    }
}
