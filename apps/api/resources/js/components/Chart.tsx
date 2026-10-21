import { Chart as ChartJS, registerables, type ChartConfiguration, type ChartType, type TooltipModel } from 'chart.js';
import { useEffect, useRef, useState, type JSX } from 'react';

ChartJS.register(...registerables);

/**
 * The hovered values, as the app's own tooltip rather than Chart.js's.
 *
 * Chart.js draws its tooltip on the canvas, which means it can be given colours but never the
 * interface's actual box — no `--shadow-float`, no border radius token, no type scale, and a
 * second set of numbers to keep in step with the stylesheet by hand. `tooltip.external` hands the
 * model over instead and leaves the drawing to us, so the box is one `<div>` wearing `.chart-tip`
 * and changes with the theme like everything else on the page.
 *
 * Built as nodes rather than markup: every line here is a number or a date, but a chart label can
 * come from a counterparty's name, and `textContent` cannot be made to execute.
 */
function paint(element: HTMLDivElement, tooltip: TooltipModel<ChartType>): void {
    const nodes: HTMLElement[] = [];
    const title = tooltip.title.join(' ');

    if (title !== '') {
        const head = document.createElement('div');

        head.className = 'chart-tip-title';
        head.textContent = title;
        nodes.push(head);
    }

    const lines = tooltip.body.flatMap((entry) => entry.lines);
    const single = lines.at(0);

    // One dataset needs no key: the figure is the whole content, so it is set as the figure. Several
    // get a swatch each, because the only thing telling them apart on the chart is their colour.
    if (lines.length === 1 && single !== undefined) {
        const value = document.createElement('div');

        value.className = 'chart-tip-value';
        value.textContent = single;
        nodes.push(value);
    } else {
        const list = document.createElement('ul');

        list.className = 'chart-tip-rows';

        lines.forEach((line, index) => {
            const row = document.createElement('li');
            const dot = document.createElement('span');
            const colour = tooltip.labelColors.at(index)?.borderColor;

            dot.className = 'chart-tip-dot';
            dot.style.background = typeof colour === 'string' ? colour : 'transparent';
            row.className = 'chart-tip-row';
            row.append(dot, document.createTextNode(line));
            list.append(row);
        });

        nodes.push(list);
    }

    element.replaceChildren(...nodes);
}

/**
 * Centred over the hovered point and above it, kept inside the canvas.
 *
 * Measured after the content is in, since a box that has not been filled yet has no width to centre
 * on. It flips below the point when there is no room above — near the top of a line chart that is
 * most of the plot area, and a tooltip clipped by the panel is worse than one under the cursor.
 */
function place(element: HTMLDivElement, chart: ChartJS, tooltip: TooltipModel<ChartType>): void {
    const half = element.offsetWidth / 2;
    const left = Math.min(Math.max(tooltip.caretX, half + 2), chart.width - half - 2);
    const above = tooltip.caretY - element.offsetHeight - 10;

    element.style.left = `${String(left)}px`;
    element.style.top = `${String(above < 0 ? tooltip.caretY + 12 : above)}px`;
}

/**
 * The same configuration with its tooltip handed to `element`.
 *
 * A chart that turned its tooltip off keeps it off — `external` is called from the tooltip's own
 * update and does not consult `enabled`, so the donuts would otherwise gain a tooltip they were
 * deliberately not given.
 */
function withHtmlTooltip(config: ChartConfiguration, element: HTMLDivElement): ChartConfiguration {
    const tooltip = config.options?.plugins?.tooltip;

    if (tooltip?.enabled === false) {
        return config;
    }

    return {
        ...config,
        options: {
            ...config.options,
            plugins: {
                ...config.options?.plugins,
                tooltip: {
                    ...tooltip,
                    // Off, meaning "do not draw on the canvas". The handler below still runs.
                    enabled: false,
                    external: ({ chart, tooltip: model }) => {
                        if (model.opacity === 0) {
                            element.style.opacity = '0';

                            return;
                        }

                        paint(element, model);
                        place(element, chart, model);
                        element.style.opacity = '1';
                    },
                },
            },
        },
    };
}

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
    const tip = useRef<HTMLDivElement>(null);
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
        const tooltip = tip.current;

        if (element === null || tooltip === null) {
            return;
        }

        const chart = new ChartJS(element, withHtmlTooltip(props.config, tooltip));

        return () => {
            chart.destroy();
        };
    }, [props.config, theme]);

    // The canvas is a picture of numbers that are also in a table below it on every panel that has
    // one, so it is labelled rather than described: the reading is available without it.
    //
    // The frame is what the tooltip is positioned against — Chart.js reports the hovered point in
    // canvas coordinates, which are the frame's own only while the two share an origin.
    return (
        <div className="chart-frame">
            <canvas ref={canvas} className={props.className} role="img" aria-label={props.label} />
            <div ref={tip} className="chart-tip" aria-hidden="true" />
        </div>
    );
}
