/**
 * Keeps an iPhone or iPad from zooming into a field it is typing in, without making the field's text
 * bigger.
 *
 * Safari zooms the page into any input whose text is under 16px, and these screens' controls are
 * 13px. Raising them to 16px on every touch screen stopped it, at the cost of every form reading a
 * size too large. `maximum-scale=1` stops the zoom instead, and on iOS only that: since iOS 10 Safari
 * ignores it for a pinch, so a person can still zoom the page with two fingers. Elsewhere it would
 * take that pinch away — and Android never zooms into a field to begin with — so nothing else gets it.
 */
export function stopFieldZoomOnIos(
    agent: string = navigator.userAgent,
    touchPoints: number = navigator.maxTouchPoints,
): void {
    // An iPad asks for the desktop site and calls itself a Mac; the touch screen is what gives it away.
    const ios = /iPhone|iPad|iPod/.test(agent) || (agent.includes('Macintosh') && touchPoints > 1);
    const meta = document.querySelector<HTMLMetaElement>('meta[name="viewport"]');

    if (!ios || meta === null || /maximum-scale/.test(meta.content)) {
        return;
    }

    meta.content = `${meta.content}, maximum-scale=1`;
}
