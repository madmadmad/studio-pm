import { useEffect, useRef, useState } from 'react';
import { formatCurrency } from '../lib/format';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const HEIGHT = 240;
const PAD = { top: 12, right: 8, bottom: 28, left: 52 };

// "$3.5k", "$12k", "-$400" -- the axis's short form.
function compact(value) {
    const sign = value < 0 ? '-' : '';
    const v = Math.abs(value);
    if (v >= 1000) return `${sign}$${(v / 1000).toFixed(v % 1000 === 0 || v >= 10000 ? 0 : 1)}k`;
    return `${sign}$${Math.round(v)}`;
}

// Gridline steps that land on round numbers (1, 2, 2.5, 5 x 10^n).
function niceStep(range, count = 4) {
    const raw = range / count || 1;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].find((n) => n * magnitude >= raw) * magnitude;
    return step;
}

// Bookkeeping's series: income and expenses as bars, net as the line.
const BOOKKEEPING = {
    bars: [
        { key: 'income', label: 'Income', tone: 'primary' },
        { key: 'expenses', label: 'Expenses', tone: 'muted' },
    ],
    line: { key: 'net', label: 'Net' },
    ariaLabel: 'Income, expenses and net by month',
};

// The year at a glance: a pair of bars per month with a line over them,
// months still to come left empty. Hover a month for its figures. Which
// figures is `series` -- Bookkeeping's by default (income, expenses, net);
// Expenses passes its own. Colors come from the theme tokens (the first
// bar the red, the second grey, the line the text color), so it reads in
// dark and light alike. Drawn to the width it's given.
export default function YearChart({ months, year, series = BOOKKEEPING }) {
    const ref = useRef(null);
    const [width, setWidth] = useState(0);
    const [hover, setHover] = useState(null);

    useEffect(() => {
        const el = ref.current;
        if (!el) return undefined;
        const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const { bars, line } = series;
    const [first, second] = bars;
    // A month is past (or current) once it has figures; future ones are null.
    const isKnown = (m) => m[line.key] !== null;
    const known = months.filter(isKnown);
    const values = known.flatMap((m) => [m[first.key], m[second.key], m[line.key]]);
    const top = Math.max(0, ...values);
    const bottom = Math.min(0, ...values);
    const step = niceStep(top - bottom || 1);
    const max = Math.ceil(top / step) * step || step;
    const min = Math.floor(bottom / step) * step;
    const ticks = [];
    for (let v = min; v <= max + step / 2; v += step) ticks.push(v);

    const plotW = Math.max(width - PAD.left - PAD.right, 0);
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const y = (v) => PAD.top + ((max - v) / (max - min || 1)) * plotH;
    const group = plotW / 12;
    const barW = Math.max(Math.min(group * 0.28, 22), 2);
    const gx = (i) => PAD.left + i * group;
    const cx = (i) => gx(i) + group / 2;
    const bar = (value) => ({ y: Math.min(y(value), y(0)), h: Math.abs(y(value) - y(0)) });

    const linePoints = months.map((m, i) => (isKnown(m) ? `${cx(i)},${y(m[line.key])}` : null)).filter(Boolean);
    const total = (key) => known.reduce((sum, m) => sum + m[key], 0);

    const hovered = hover !== null ? months[hover] : null;

    return (
        <div className="year-chart">
            <div className="year-chart__head">
                <div className="year-chart__title">{year} so far</div>
                <div className="year-chart__legend">
                    {bars.map((b) => (
                        <span key={b.key} className={`year-chart__key year-chart__key--${b.tone}`}>{b.label} <strong>{formatCurrency(total(b.key))}</strong></span>
                    ))}
                    <span className="year-chart__key year-chart__key--line">{line.label} <strong>{formatCurrency(total(line.key))}</strong></span>
                </div>
            </div>

            <div ref={ref} className="year-chart__plot" onMouseLeave={() => setHover(null)}>
                {width > 0 && (
                    <svg width={width} height={HEIGHT} role="img" aria-label={`${series.ariaLabel}, ${year}`}>
                        {ticks.map((t) => (
                            <g key={t}>
                                <line x1={PAD.left} x2={width - PAD.right} y1={y(t)} y2={y(t)} className={t === 0 ? 'year-chart__zero' : 'year-chart__grid'} />
                                <text x={PAD.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="year-chart__label">{compact(t)}</text>
                            </g>
                        ))}

                        {months.map((m, i) => (
                            <g key={m.month}>
                                {hover === i && <rect x={gx(i)} y={PAD.top} width={group} height={plotH} className="year-chart__hover" />}
                                {isKnown(m) && (
                                    <>
                                        <rect x={cx(i) - barW - 1.5} y={bar(m[first.key]).y} width={barW} height={bar(m[first.key]).h} rx={2} className={`year-chart__bar year-chart__bar--${first.tone}`} />
                                        <rect x={cx(i) + 1.5} y={bar(m[second.key]).y} width={barW} height={bar(m[second.key]).h} rx={2} className={`year-chart__bar year-chart__bar--${second.tone}`} />
                                    </>
                                )}
                                <text x={cx(i)} y={HEIGHT - 8} textAnchor="middle" className={`year-chart__label${isKnown(m) ? '' : ' year-chart__label--future'}`}>{MONTHS[i]}</text>
                                <rect x={gx(i)} y={PAD.top} width={group} height={plotH} fill="transparent" onMouseEnter={() => setHover(i)} />
                            </g>
                        ))}

                        {linePoints.length > 1 && <polyline points={linePoints.join(' ')} className="year-chart__line" />}
                        {months.map((m, i) => isKnown(m) && (
                            <circle key={m.month} cx={cx(i)} cy={y(m[line.key])} r={hover === i ? 4.5 : 3} className="year-chart__dot" pointerEvents="none" />
                        ))}
                    </svg>
                )}

                {hovered && isKnown(hovered) && (
                    <div
                        className={`year-chart__tip${hover >= 8 ? ' year-chart__tip--left' : ''}`}
                        style={{ left: hover >= 8 ? gx(hover) - 8 : gx(hover) + group + 8 }}
                    >
                        <div className="year-chart__tip-month">{MONTHS[hover]} {year}</div>
                        {bars.map((b) => (
                            <div key={b.key} className="year-chart__tip-row"><span className={`year-chart__key year-chart__key--${b.tone}`}>{b.label}</span>{formatCurrency(hovered[b.key])}</div>
                        ))}
                        <div className="year-chart__tip-row year-chart__tip-row--line"><span className="year-chart__key year-chart__key--line">{line.label}</span>{formatCurrency(hovered[line.key])}</div>
                    </div>
                )}
            </div>
        </div>
    );
}
