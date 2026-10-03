import { Chart as ChartJS, registerables, type ChartConfiguration } from 'chart.js';
import { useEffect, useRef, useState, type JSX } from 'react';

ChartJS.register(...registerables);

/**
 * One chart, drawn on a canvas that React does not own.
 *
 * Chart.js writes into the canvas itself, so the element is created once and handed over; React is
 * told nothing about what happens inside it. The instance is destroyed on unmount because a live
 * chart keeps listeners on `window` for resize and pointer events, and a page that mounts a new one
 * each time the period changes would accumulate them.
 *
 * ## Why it watches the document element
 *
 * Its colours are read from the theme's CSS variables when the configuration is built, which makes
 * them plain strings by the time Chart.js sees them — so a chart drawn in light mode stays light
 * after the theme switches, alone on a dark page. The shell stamps the theme on `<html>`, so that is
 * what is watched: when it changes, the chart is rebuilt against the new values.
 */
export function Chart(props: { config: ChartConfiguration; className?: string; label: string }): JSX.Element {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [theme, setTheme] = useState(0);

    useEffect(() => {
        const observer = new MutationObserver(() => {
            setTheme((previous) => previous + 1);
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'data-theme'],
        });

        return () => {
            observer.disconnect();
        };
    }, []);

    useEffect(() => {
        const element = canvas.current;

        if (element === null) {
            return;
        }

        const chart = new ChartJS(element, props.config);

        return () => {
            chart.destroy();
        };
    }, [props.config, theme]);

    // The canvas is a picture of numbers that are also in a table below it on every panel that has
    // one, so it is labelled rather than described: the reading is available without it.
    return <canvas ref={canvas} className={props.className} role="img" aria-label={props.label} />;
}
