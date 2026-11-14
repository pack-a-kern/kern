import type { Plugin } from 'chart.js';

import { themeColor } from './themeColor';

/**
 * A vertical line at the day under the cursor.
 *
 * Chart.js draws none, and without it the tooltip is a box floating over a line with nothing saying
 * which of sixty points it belongs to. Registered on a chart that asks for it — a plugin in the configuration
 * applies to its own instance, unlike `ChartJS.register`, which would put the line on every chart in
 * the app.
 */
export const hoverLine: Plugin<'line'> = {
    id: 'hoverLine',
    afterDatasetsDraw(chart) {
        const hovered = chart.getActiveElements().at(0);

        if (hovered === undefined) {
            return;
        }

        const { ctx, chartArea } = chart;

        ctx.save();
        ctx.beginPath();
        ctx.moveTo(hovered.element.x, chartArea.top);
        ctx.lineTo(hovered.element.x, chartArea.bottom);
        ctx.lineWidth = 1;
        ctx.strokeStyle = themeColor('--muted-foreground', 0.5);
        ctx.stroke();
        ctx.restore();
    },
};
