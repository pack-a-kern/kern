<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\Contracts\NoticeType;
use Kern\Platform\Tenancy\Contracts\TenantGate;

/**
 * What every notice this product sends has in common: it is about one taxpayer.
 *
 * So it is shown only to someone who may still act for that taxpayer — a membership revoked after the
 * notice was sent ends what it shows, as it ends everything else — and its link opens the page in that
 * taxpayer, whichever one the person had open (`?subject=`, read once when the page loads).
 */
abstract readonly class TaxpayerNotice implements NoticeType
{
    public function __construct(private TenantGate $gate)
    {
    }

    /**
     * The notices whose taxpayer `$notifiable` may still act for — asked once for all of them, and
     * asked as a list rather than one refusal at a time, so a bell is not a stream of denied-access
     * entries in the security log.
     *
     * @param  array<string, array<string, mixed>>  $notices
     * @return array<string, array{subject: string, data: array<string, mixed>}> by notice id
     */
    protected function visibleTo(Model $notifiable, array $notices): array
    {
        $person = $notifiable->getKey();
        $taxpayers = is_string($person) ? array_flip($this->gate->tenantsFor($person)) : [];
        $visible = [];

        foreach ($notices as $id => $data) {
            $subject = $data['subject_id'] ?? null;

            if (is_string($subject) && isset($taxpayers[$subject])) {
                $visible[$id] = ['subject' => $subject, 'data' => $data];
            }
        }

        return $visible;
    }

    /** @param array<string, string> $query */
    protected function link(string $path, string $subjectId, array $query = []): string
    {
        return $path . '?' . http_build_query([...$query, 'subject' => $subjectId]);
    }
}
