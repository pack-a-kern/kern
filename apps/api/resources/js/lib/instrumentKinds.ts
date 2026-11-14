import { Building2, ChartCandlestick, Factory, Landmark, type LucideIcon } from 'lucide-react';

import type { InstrumentKind } from '../types/investments';

/**
 * How each kind of instrument is told apart at a glance: a mark and a colour of its own (`.tone-*` in
 * `app.css`), always beside its name, so the colour is never the only thing that says which kind it is.
 */
export const INSTRUMENT_KIND_LOOK: Record<InstrumentKind, { icon: LucideIcon; tone: string }> = {
    GOVERNMENT_BOND: { icon: Landmark, tone: 'tone-blue' },
    MUNICIPAL_BOND: { icon: Building2, tone: 'tone-teal' },
    CORPORATE_BOND: { icon: Factory, tone: 'tone-orange' },
    SHARE: { icon: ChartCandlestick, tone: 'tone-violet' },
};
