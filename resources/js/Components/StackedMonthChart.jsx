import { useEffect, useRef, useState } from 'react';
import { formatCurrency } from '../lib/format';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const HEIGHT = 280;
const PAD = { top: 12, right: 8, bottom: 40, left: 52 };

// "$3.5k", "$12k" -- the axis's short form.
function compact(value) {
    const v = Math.abs(value);
    if (v >= 1000) return `${value < 0 ? '-' : ''}$${(v / 1000).toFixed(v % 1000 === 0 || v >= 10000 ? 0 : 1)}k`;
    return `${value < 0 ? '-' : ''}$${Math.round(v)}`;
}

// Gridline steps that land on round numbers (1, 2, 2.5, 5 x 10^n).
function niceStep(range, count = 4) {
    const raw = range / count || 1;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    return [1, 2, 2.5, 5, 10].find((n) => n * magnitude >= raw) * magnitude;
}

// 'YYYY-MM' => { short: 'Jan', long: 'Jan 2026', year: '2026', isJanuary }
function monthName(ym) {
    const [y, m] = ym.split('-').map(Number);
    return { short: MONTHS[m - 1], long: `${MONTHS[m - 1]} ${y}`, year: String(y), isJanuary: m === 1 };
}

// Months as stacked bars, one segment per series, with the month's total
// over it on hover. `months` is ['YYYY-MM', ...]; each series is
// { key, label, color (1-9, the --color-chart-N tokens), values:
// { 'YYYY-MM': dollars } }. Clicking a key in the legend hides or shows
// its series. Colors come from theme tokens, so it reads in dark and
// light alike. Drawn to the width it's given.
export default function StackedMonthChart({ months, series, title, ariaLabel }) {
    const ref = useRef(null);
    const [width, setWidth] = useState(0);
    const [hover, setHover] = useState(null);
    const [hidden, setHidden] = useState({});

    useEffect(() => {
        const el = ref.current;
        if (!el) return undefined;
        const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const shown = series.filter((s) => !hidden[s.key]);
    const monthTotal = (ym) => shown.reduce((sum, s) => sum + Math.max(s.values[ym] ?? 0, 0), 0);
    const top = Math.max(1, ...months.map(monthTotal));
    const step = niceStep(top);
    const max = Math.ceil(top / step) * step;
    const ticks = [];
    for (let v = 0; v <= max + step / 2; v += step) ticks.push(v);

    const plotW = Math.max(width - PAD.left - PAD.right, 0);
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const y = (v) => PAD.top + ((max - v) / max) * plotH;
    const group = months.length ? plotW / months.length : 0;
    const barW = Math.max(Math.min(group * 0.6, 36), 2);
    const gx = (i) => PAD.left + i * group;
    const cx = (i) => gx(i) + group / 2;
    // Too narrow for every month's name: every other one.
    const every = group < 30 ? 2 : 1;
    const flip = hover !== null && hover >= months.length * 0.6;
    const total = (s) => months.reduce((sum, ym) => sum + (s.values[ym] ?? 0), 0);

    return (
        <div className="year-chart">
            <div className="year-chart__head">
                <div className="year-chart__title">{title}</div>
                <div className="year-chart__legend">
                    {series.map((s) => (
                        <button
                            key={s.key}
                            type="button"
                            aria-pressed={!hidden[s.key]}
                            onClick={() => setHidden({ ...hidden, [s.key]: !hidden[s.key] })}
                            className={`year-chart__key year-chart__key--series-${s.color} year-chart__key--toggle${hidden[s.key] ? ' year-chart__key--off' : ''}`}
                        >
                            {s.label} <strong>{formatCurrency(total(s))}</strong>
                        </button>
                    ))}
                </div>
            </div>

            <div ref={ref} className="year-chart__plot" onMouseLeave={() => setHover(null)}>
                {width > 0 && (
                    <svg width={width} height={HEIGHT} role="img" aria-label={ariaLabel}>
                        {ticks.map((t) => (
                            <g key={t}>
                                <line x1={PAD.left} x2={width - PAD.right} y1={y(t)} y2={y(t)} className={t === 0 ? 'year-chart__zero' : 'year-chart__grid'} />
                                <text x={PAD.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="year-chart__label">{compact(t)}</text>
                            </g>
                        ))}

                        {months.map((ym, i) => {
                            const name = monthName(ym);
                            let base = 0;
                            return (
                                <g key={ym}>
                                    {hover === i && <rect x={gx(i)} y={PAD.top} width={group} height={plotH} className="year-chart__hover" />}
                                    {shown.map((s) => {
                                        const v = Math.max(s.values[ym] ?? 0, 0);
                                        if (v === 0) return null;
                                        const rect = <rect key={s.key} x={cx(i) - barW / 2} y={y(base + v)} width={barW} height={Math.max(y(base) - y(base + v) - 1, 0.5)} className={`year-chart__bar--series-${s.color}`} />;
                                        base += v;
                                        return rect;
                                    })}
                                    {i % every === 0 && <text x={cx(i)} y={HEIGHT - 22} textAnchor="middle" className="year-chart__label">{name.short}</text>}
                                    {(i === 0 || name.isJanuary) && <text x={cx(i)} y={HEIGHT - 6} textAnchor="middle" className="year-chart__label year-chart__label--future">{name.year}</text>}
                                    <rect x={gx(i)} y={PAD.top} width={group} height={plotH} fill="transparent" onMouseEnter={() => setHover(i)} />
                                </g>
                            );
                        })}
                    </svg>
                )}

                {hover !== null && months[hover] && (
                    <div className={`year-chart__tip${flip ? ' year-chart__tip--left' : ''}`} style={{ left: flip ? gx(hover) - 8 : gx(hover) + group + 8 }}>
                        <div className="year-chart__tip-month">{monthName(months[hover]).long}</div>
                        {[...shown].reverse().map((s) => (
                            <div key={s.key} className="year-chart__tip-row">
                                <span className={`year-chart__key year-chart__key--series-${s.color}`}>{s.label}</span>
                                {formatCurrency(s.values[months[hover]] ?? 0)}
                            </div>
                        ))}
                        <div className="year-chart__tip-row year-chart__tip-row--line"><span>Total</span>{formatCurrency(monthTotal(months[hover]))}</div>
                    </div>
                )}
            </div>
        </div>
    );
}
