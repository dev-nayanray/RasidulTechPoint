/**
 * Vanilla-JS SVG bar chart for the dashboard sales trend.
 *
 * No external charting library — the SVG is built from the data
 * embedded in the <svg data-chart> element's data-chart-data attribute
 * (a JSON array of {date, total, count}). The chart only renders when
 * the server emitted real data; an empty database means no chart at
 * all (never fake bars).
 *
 * Accessibility: the SVG has role="img" and an aria-label set by the
 * server; hovering a bar shows a <title> tooltip with the exact value.
 */
function renderChart(svg) {
    const data = JSON.parse(svg.getAttribute('data-chart-data') || '[]');
    const format = svg.getAttribute('data-chart-format') || 'money';
    if (!Array.isArray(data) || data.length === 0) {
        return;
    }

    const width = svg.clientWidth || svg.parentElement.clientWidth || 600;
    const height = parseInt(svg.style.height, 10) || 160;
    const padding = { top: 12, right: 8, bottom: 8, left: 8 };
    const barGap = 6;
    const innerWidth = width - padding.left - padding.right;
    const innerHeight = height - padding.top - padding.bottom;
    const barWidth = (innerWidth - barGap * (data.length - 1)) / data.length;

    const max = Math.max(...data.map((d) => d.total), 1);
    // Round the y-axis max up to a "nice" number for readable gridlines.
    const niceMax = niceMax(max);

    const ns = 'http://www.w3.org/2000/svg';
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('preserveAspectRatio', 'none');

    // Clear any previous render (e.g. after resize).
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const fmt = (value) => {
        if (format === 'money') {
            const n = Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 });
            return n;
        }
        return String(value);
    };

    // Gridlines (4 horizontal lines).
    for (let i = 0; i <= 4; i++) {
        const y = padding.top + (innerHeight / 4) * i;
        const line = document.createElementNS(ns, 'line');
        line.setAttribute('x1', padding.left);
        line.setAttribute('x2', width - padding.right);
        line.setAttribute('y1', y);
        line.setAttribute('y2', y);
        line.setAttribute('stroke', '#e2e8f0');
        line.setAttribute('stroke-width', '1');
        line.setAttribute('stroke-dasharray', i === 4 ? '' : '2 4');
        svg.appendChild(line);
    }

    // Bars.
    data.forEach((day, i) => {
        const barH = max > 0 ? (day.total / niceMax) * innerHeight : 0;
        const x = padding.left + i * (barWidth + barGap);
        const y = padding.top + innerHeight - barH;
        const rect = document.createElementNS(ns, 'rect');
        rect.setAttribute('x', String(x));
        rect.setAttribute('y', String(y));
        rect.setAttribute('width', String(Math.max(2, barWidth)));
        rect.setAttribute('height', String(barH));
        rect.setAttribute('rx', '2');
        rect.setAttribute('fill', day.total > 0 ? '#2563EB' : '#e2e8f0');
        rect.setAttribute('class', 'transition-opacity duration-150');
        rect.style.opacity = day.total > 0 ? '1' : '0.5';

        // Tooltip via SVG <title>.
        const title = document.createElementNS(ns, 'title');
        title.textContent = `${day.date}: ${fmt(day.total)} (${day.count} order${day.count === 1 ? '' : 's'})`;
        rect.appendChild(title);

        svg.appendChild(rect);
    });

    // Sum label.
    const totalEl = document.querySelector('[data-chart-total-label]');
    if (totalEl) {
        const sum = data.reduce((acc, d) => acc + Number(d.total), 0);
        totalEl.textContent = `Σ ${fmt(sum)} · ${data.length} days`;
    }
}

function niceMax(value) {
    if (value <= 0) return 1;
    const exp = Math.floor(Math.log10(value));
    const base = Math.pow(10, exp);
    const ratio = value / base;
    let nice;
    if (ratio <= 1) nice = 1;
    else if (ratio <= 2) nice = 2;
    else if (ratio <= 5) nice = 5;
    else nice = 10;
    return nice * base;
}

function renderAll() {
    document.querySelectorAll('[data-chart]').forEach(renderChart);
}

// Initial render.
if (document.readyState !== 'loading') {
    renderAll();
} else {
    document.addEventListener('DOMContentLoaded', renderAll);
}

// Re-render on resize so the bars stay responsive. Throttled.
let resizeTimer = null;
window.addEventListener('resize', () => {
    if (resizeTimer) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(renderAll, 150);
});
