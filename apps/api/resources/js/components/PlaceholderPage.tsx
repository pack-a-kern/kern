import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';
import type { JSX } from 'react';
import AppShell from './AppShell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslation } from '../i18n';

/**
 * The one reusable "not built yet" screen every menu item without a real page renders
 * (`docs/handoff/040-ui-foundation.md` "Placeholder screens") — driven entirely by props, so
 * adding another future screen to the navigation never means copy-pasting a 24th near-identical
 * file (`docs/CODE-QUALITY.md` §1). It states plainly what the screen will do and what it is
 * waiting on, and is visibly marked "Încă neconstruit" / "Not built yet" so nobody mistakes an
 * empty screen for a bug.
 */
export default function PlaceholderPage({
    title,
    description,
    blockedOn,
}: {
    title: string;
    description: string;
    /** What real thing (an endpoint, a milestone, a human approval) this screen needs before it
     * can show anything real. Omitted for screens this UI-foundation pass left genuinely partial
     * rather than fully blocked (e.g. Security, Tax subjects). */
    blockedOn?: string;
}): JSX.Element {
    const { t } = useTranslation();

    return (
        <AppShell>
            <Head title={title} />
            <Card>
                <CardHeader className="flex-row items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <Construction className="size-5 text-muted-foreground" aria-hidden="true" />
                        <CardTitle className="text-xl">{title}</CardTitle>
                    </div>
                    <Badge variant="outline">{t((d) => d.placeholder.badge)}</Badge>
                </CardHeader>
                <CardContent className="space-y-3">
                    <p className="text-sm text-foreground">{description}</p>
                    {blockedOn && (
                        <p className="text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                                {t((d) => d.placeholder.blockedOnLabel)}:
                            </span>{' '}
                            {blockedOn}
                        </p>
                    )}
                </CardContent>
            </Card>
        </AppShell>
    );
}
