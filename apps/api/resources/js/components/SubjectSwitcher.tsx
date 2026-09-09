import { Link } from '@inertiajs/react';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { listSubjects } from '../api/subjectsApi';
import { getActiveSubjectId, setActiveSubjectId } from '../lib/subjectStore';
import type { SubjectSummary } from '../types/subjects';
import { useTranslation } from '../i18n';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The topbar's compact "which tax subject am I working on" switcher — every other page still
 * reads `resources/js/lib/subjectStore.ts` once per mount (`docs/handoff/030-pwa-frontend.md`), so
 * switching here does a full navigation rather than a client-side state update, guaranteeing every
 * already-mounted page re-reads the new value instead of a subset silently going stale. Only the
 * first page of subjects is listed — a user with more than that manages the rest from
 * `/setari/subiecte` (`resources/js/pages/settings/subjects.tsx`, linked below), which already
 * paginates.
 */
export default function SubjectSwitcher(): JSX.Element {
    const { t } = useTranslation();
    const [subjects, setSubjects] = useState<SubjectSummary[]>([]);
    const [activeId, setActiveId] = useState<string | null>(() => getActiveSubjectId());

    useEffect(() => {
        void (async () => {
            try {
                const page = await listSubjects();
                setSubjects(page.data);
            } catch {
                // Best-effort convenience UI — a failure here just leaves the switcher empty;
                // `/setari/subiecte` is the authoritative screen and surfaces errors.
            }
        })();
    }, []);

    const active = subjects.find((subject) => subject.id === activeId);

    function activate(subjectId: string): void {
        setActiveSubjectId(subjectId);
        setActiveId(subjectId);
        window.location.reload();
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="max-w-40 justify-between gap-1.5 sm:max-w-56">
                    <span className="truncate">{active ? active.displayName : t((d) => d.shell.noActiveSubject)}</span>
                    <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuLabel>{t((d) => d.shell.activeSubject)}</DropdownMenuLabel>
                {subjects.map((subject) => (
                    <DropdownMenuItem
                        key={subject.id}
                        onSelect={() => {
                            activate(subject.id);
                        }}
                    >
                        {subject.id === activeId && <Check className="size-4" />}
                        {subject.displayName}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href="/setari/subiecte">{t((d) => d.shell.manageSubjects)}</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
