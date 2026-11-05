import { describe, expect, it } from 'vitest';
import { bankHue, bankInitials } from '../bankMark';

describe('a bank’s initials', () => {
    it.each([
        ['Banca Transilvania', 'BT'],
        ['Banca Comerciala Romana', 'BCR'],
        ['BRD - Groupe Societe Generale', 'BRD'],
        ['CEC Bank', 'CEC'],
        ['ING Bank', 'ING'],
        ['Exim Banca Romaneasca', 'EBR'],
        // Joining words are left out: "de", "si".
        ['Banca de Investitii si Dezvoltare', 'BID'],
        ['Banca Romana de Credite si Investitii', 'BRC'],
        ['Vista Bank (Romania)', 'VBR'],
    ])('reads %s as %s', (name, initials) => {
        expect(bankInitials(name)).toBe(initials);
    });

    it('still draws something for a name with no letters', () => {
        expect(bankInitials('123')).toBe('?');
    });
});

describe('a bank’s hue', () => {
    it('is the same for the same bank, every time', () => {
        expect(bankHue('BTRL')).toBe(bankHue('BTRL'));
    });

    it('tells banks apart, and stays on the colour wheel', () => {
        const hues = ['BTRL', 'RNCB', 'BRDE', 'CECE', 'INGB'].map(bankHue);

        expect(new Set(hues).size).toBe(hues.length);
        expect(hues.every((hue) => hue >= 0 && hue < 360)).toBe(true);
    });
});
