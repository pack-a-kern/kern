import type { JSX } from 'react';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '../i18n';
import type { DocumentStatus } from '../types/documents';

/**
 * Soft-tinted status colours (tint background + full-strength text, mirroring `Badge`'s own
 * `destructive` variant: `bg-destructive/10 text-destructive`) built on the theme's `--success` /
 * `--warning` / `--info` tokens (`resources/css/app.css`) rather than the hardcoded
 * `bg-emerald-100 text-emerald-800` classes the pre-conversion component used — those had no dark
 * variant at all. `cn()` (tailwind-merge) drops `Badge`'s default `bg-primary text-primary-
 * foreground` in favour of whichever of these classes is passed, since it dedupes conflicting
 * `bg-*`/`text-*` utilities per `resources/js/lib/utils.ts`.
 */
const COLORS: Record<DocumentStatus, string> = {
    QUARANTINE: 'bg-warning/15 text-warning dark:bg-warning/20',
    SCANNED: 'bg-info/10 text-info dark:bg-info/20',
    PROCESSING: 'bg-info/10 text-info dark:bg-info/20',
    READY: 'bg-success/10 text-success dark:bg-success/20',
    REJECTED: 'bg-destructive/10 text-destructive dark:bg-destructive/20',
};

export default function StatusBadge({ status }: { status: DocumentStatus }): JSX.Element {
    const { t } = useTranslation();

    return <Badge className={COLORS[status]}>{t((d) => d.statusBadge[status])}</Badge>;
}
