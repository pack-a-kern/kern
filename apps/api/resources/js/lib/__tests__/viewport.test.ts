import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { stopFieldZoomOnIos } from '../viewport';

const BASE = 'width=device-width, initial-scale=1, viewport-fit=cover';

function viewport(): HTMLMetaElement {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="viewport"]');

    if (meta === null) {
        throw new Error('no viewport tag');
    }

    return meta;
}

beforeEach(() => {
    const meta = document.createElement('meta');
    meta.name = 'viewport';
    meta.content = BASE;
    document.head.append(meta);
});

afterEach(() => {
    viewport().remove();
});

describe('the zoom into a field', () => {
    it('is stopped on an iPhone, once however often it is asked', () => {
        const iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15';

        stopFieldZoomOnIos(iphone, 5);
        stopFieldZoomOnIos(iphone, 5);

        expect(viewport().content).toBe(`${BASE}, maximum-scale=1`);
    });

    it('is stopped on an iPad that calls itself a Mac', () => {
        stopFieldZoomOnIos('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15', 5);

        expect(viewport().content).toBe(`${BASE}, maximum-scale=1`);
    });

    it('is left alone on Android and on a Mac, where the setting would only take the pinch away', () => {
        stopFieldZoomOnIos('Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36', 5);
        stopFieldZoomOnIos('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15', 0);

        expect(viewport().content).toBe(BASE);
    });
});
