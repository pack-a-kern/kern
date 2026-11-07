import { describe, expect, it } from 'vitest';

import { parseSettingGroups } from '../settings';

/** A setting as `GET /api/v1/settings` sends it. */
function setting(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        key: 'legislation.portal_search',
        group: 'legislation_portal',
        type: 'choice',
        choices: ['soap', 'browser'],
        source: 'inherited',
        value: 'soap',
        has_value: true,
        hint: null,
        read_only: false,
        config_path: 'sources.entries.LEGISLATIE.search',
        updated_at: null,
        updated_by: null,
        ...overrides,
    };
}

describe('parseSettingGroups', () => {
    it('keeps the values a choice may hold, which the screen offers and nothing else', () => {
        const [group] = parseSettingGroups({ groups: [{ group: 'legislation_portal', settings: [setting()] }] });

        expect(group?.settings[0]?.choices).toEqual(['soap', 'browser']);
    });

    it('refuses a setting without its list of choices, rather than offering an empty select', () => {
        const withoutChoices = setting();
        delete withoutChoices.choices;

        expect(() =>
            parseSettingGroups({ groups: [{ group: 'legislation_portal', settings: [withoutChoices] }] }),
        ).toThrow(TypeError);
    });
});
