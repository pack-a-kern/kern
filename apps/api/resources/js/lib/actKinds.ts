import { BadgeCheck, FileText, Landmark, Scale, ScrollText, Stamp, Zap, type LucideIcon } from 'lucide-react';

/**
 * How each kind of act is told apart at a glance: a mark and a colour of its own (`.act-*` in `app.css`),
 * always beside its name, so the colour is never the only thing that says which kind it is. A kind the
 * portal has that is not here takes the plain one.
 */
const KINDS: Record<string, { icon: LucideIcon; tone: string } | undefined> = {
    LEGE: { icon: Landmark, tone: 'act-lege' },
    'ORDONANTA DE URGENTA': { icon: Zap, tone: 'act-oug' },
    ORDONANTA: { icon: ScrollText, tone: 'act-og' },
    HOTARARE: { icon: FileText, tone: 'act-hg' },
    ORDIN: { icon: Stamp, tone: 'act-ordin' },
    DECIZIE: { icon: BadgeCheck, tone: 'act-decizie' },
};

/** The mark and the colour class of an act's kind, by its filing key — `HOTARARE`. */
export function actKind(type: string): { icon: LucideIcon; tone: string } {
    return KINDS[type] ?? { icon: Scale, tone: 'act-other' };
}
