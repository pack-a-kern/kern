<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Enums\Level;
use Kern\Notifications\Notice;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\Enums\ImportNoticeKind;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * A statement import moved on without the person who started it: read, ready to review, imported, or
 * stopped.
 *
 * Stored with ids and counts only; the bell and the device both say which moment it is and how many
 * rows — the counts of that moment — and open the import. An import since deleted has nothing to open,
 * and its notices are left out.
 */
final readonly class StatementImportNotice extends TaxpayerNotice
{
    public static function key(): string
    {
        return 'statement-import';
    }

    public static function about(StatementImport $import, ImportNoticeKind $kind, int $rowsSuggested = 0): Notice
    {
        return new Notice(self::class, [
            'kind' => $kind->value,
            'subject_id' => $import->tax_subject_id,
            'import_id' => $import->id,
            'row_count' => $import->row_count,
            'imported_count' => $import->imported_count,
            'rows_suggested' => $rowsSuggested,
        ]);
    }

    public function label(string $locale): string
    {
        return __('tax-romania::notifications.types.statement-import.label', [], $locale);
    }

    public function hint(string $locale): string
    {
        return __('tax-romania::notifications.types.statement-import.hint', [], $locale);
    }

    public function render(array $notices, Model $notifiable, string $locale): array
    {
        $visible = $this->visibleTo($notifiable, $notices);
        $importIds = array_filter(array_column(array_column($visible, 'data'), 'import_id'), is_string(...));

        /** @var array<string, string> $existing import id => its taxpayer */
        $existing = $importIds === [] ? [] : StatementImport::query()->withoutGlobalScope(TenantScope::class)
            ->whereKey(array_values($importIds))
            ->pluck('tax_subject_id', 'id')
            ->all();

        $messages = [];

        foreach ($visible as $id => ['subject' => $subjectId, 'data' => $data]) {
            $kind = ImportNoticeKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
            $importId = $data['import_id'] ?? null;

            if ($kind === null || ! is_string($importId) || ($existing[$importId] ?? null) !== $subjectId) {
                continue;
            }

            $count = static fn (string $key): string => (string) (is_int($data[$key] ?? null) ? $data[$key] : 0);

            $messages[$id] = new Message(
                title: __("tax-romania::notifications.imports.{$kind->value}.title", [], $locale),
                body: __("tax-romania::notifications.imports.{$kind->value}.body", [
                    'rows' => $count('row_count'),
                    'imported' => $count('imported_count'),
                    'suggested' => $count('rows_suggested'),
                ], $locale),
                url: $this->link('/wallet/imports/new', $subjectId, ['import' => $importId]),
                level: $kind === ImportNoticeKind::FAILED ? Level::Error : Level::Info,
                tag: "import-{$importId}",
            );
        }

        return $messages;
    }
}
