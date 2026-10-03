<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * The counterparty prompt, with this taxpayer's own name in it.
 *
 * Two of its rules turn on that name — money arriving under it is money moving between their own
 * accounts, not income — and a rule that names nobody cannot be applied. The config holds one text
 * for every taxpayer, so it carries `{taxpayer}` and this fills it in.
 *
 * Shared by both callers, because they must ask the same question: the one that runs while an import
 * is being reviewed, and the one that runs on a schedule afterwards. Two copies of this substitution
 * would be two prompts that drift.
 *
 * ## Not a new exposure
 *
 * That name is already in the payload, printed on the person's own statement lines as a counterparty
 * — which is exactly why the rule earns its place. The model sees it either way; this is what lets it
 * know whose name it is looking at.
 */
final readonly class CounterpartyPrompt
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function system(string $subjectId): string
    {
        $prompt = $this->config->string('ai.statements.classification.system');
        $subject = TaxSubject::query()->withoutGlobalScope(TenantScope::class)->find($subjectId);
        $name = trim((string) ($subject->display_name_ciphertext ?? ''));

        /*
         * A subject with no name stored loses those lines rather than being handed an empty pair of
         * quotes to reason about — a literal placeholder reaching a model is worse than the rule
         * being absent, because it invites the model to wonder what the braces mean.
         */
        if ($name === '') {
            return trim((string) preg_replace('/^.*\{taxpayer\}.*$\n?/mu', '', $prompt));
        }

        return str_replace('{taxpayer}', $name, $prompt);
    }

    public function instruction(): string
    {
        return $this->config->string('ai.statements.classification.instruction');
    }
}
