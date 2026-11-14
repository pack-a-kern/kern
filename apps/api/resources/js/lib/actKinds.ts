import { BadgeCheck, FileText, Landmark, Scale, ScrollText, Stamp, Zap, type LucideIcon } from 'lucide-react';

/**
 * How each kind of act is told apart at a glance: a mark and a colour of its own (`.tone-*` in `app.css`),
 * always beside its name, so the colour is never the only thing that says which kind it is. A kind the
 * portal has that is not here takes the plain one.
 */
const KINDS: Record<string, { icon: LucideIcon; tone: string } | undefined> = {
    LEGE: { icon: Landmark, tone: 'tone-blue' },
    'ORDONANTA DE URGENTA': { icon: Zap, tone: 'tone-magenta' },
    ORDONANTA: { icon: ScrollText, tone: 'tone-orange' },
    HOTARARE: { icon: FileText, tone: 'tone-teal' },
    ORDIN: { icon: Stamp, tone: 'tone-violet' },
    DECIZIE: { icon: BadgeCheck, tone: 'tone-olive' },
};

/** The mark and the colour class of an act's kind, by its filing key — `HOTARARE`. */
export function actKind(type: string): { icon: LucideIcon; tone: string } {
    return KINDS[type] ?? { icon: Scale, tone: 'tone-plain' };
}
