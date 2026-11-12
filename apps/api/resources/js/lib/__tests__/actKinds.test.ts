import { Landmark, Scale, Zap } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { actKind } from '../actKinds';

describe('actKind', () => {
    it('gives each kind of act its own mark and colour', () => {
        expect(actKind('LEGE')).toEqual({ icon: Landmark, tone: 'act-lege' });
        expect(actKind('ORDONANTA DE URGENTA')).toEqual({ icon: Zap, tone: 'act-oug' });
    });

    it('gives a kind it does not know the plain one', () => {
        expect(actKind('NORMA')).toEqual({ icon: Scale, tone: 'act-other' });
    });
});
