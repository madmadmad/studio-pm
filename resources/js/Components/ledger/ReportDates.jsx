import { router } from '@inertiajs/react';
import { useState } from 'react';
import Button from '../Button';
import { todayInAppTimezone } from '../../lib/format';

// 'YYYY-MM-DD' for a local date.
function ymd(date) {
    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

// The usual stretches, counted from today in the studio's timezone.
function ranges() {
    const [y, m] = todayInAppTimezone().split('-').map(Number);
    const month = m - 1;
    const quarter = Math.floor(month / 3) * 3;
    const span = (start, end) => ({ from: ymd(start), to: ymd(end) });

    return [
        ['This month', span(new Date(y, month, 1), new Date(y, month + 1, 0))],
        ['Last month', span(new Date(y, month - 1, 1), new Date(y, month, 0))],
        ['This quarter', span(new Date(y, quarter, 1), new Date(y, quarter + 3, 0))],
        ['Last quarter', span(new Date(y, quarter - 3, 1), new Date(y, quarter, 0))],
        ['This year', span(new Date(y, 0, 1), new Date(y, 11, 31))],
        ['Last year', span(new Date(y - 1, 0, 1), new Date(y - 1, 11, 31))],
    ];
}

function asOfDays() {
    const [y, m] = todayInAppTimezone().split('-').map(Number);
    const month = m - 1;
    const quarter = Math.floor(month / 3) * 3;

    return [
        ['Today', { to: todayInAppTimezone() }],
        ['End of last month', { to: ymd(new Date(y, month, 0)) }],
        ['End of last quarter', { to: ymd(new Date(y, quarter, 0)) }],
        ['End of last year', { to: ymd(new Date(y - 1, 11, 31)) }],
    ];
}

// The dates a ledger report covers: quick picks, then the dates
// themselves. `asOf` reports (trial balance, balance sheet) take one day;
// the others a from-to range. `extra` rides along in the URL (the general
// ledger's account).
export default function ReportDates({ path, from, to, asOf = false, extra = {} }) {
    const [dates, setDates] = useState(asOf ? { to } : { from, to });
    const picks = asOf ? asOfDays() : ranges();

    function go(next) {
        setDates(next);
        router.get(path, { ...next, ...extra }, { preserveScroll: true, preserveState: true });
    }

    return (
        <div className="filter-bar">
            <div className="filter-bar__pills">
                {picks.map(([label, value]) => {
                    const active = value.to === to && (asOf || value.from === from);
                    return (
                        <button key={label} type="button" onClick={() => go(value)} className={`filter-bar__pill${active ? ' filter-bar__pill--active' : ''}`}>
                            {label}
                        </button>
                    );
                })}
            </div>
            <form onSubmit={(e) => { e.preventDefault(); go(dates); }} className="filter-bar__end inline-form">
                {!asOf && <input type="date" required aria-label="From" value={dates.from} onChange={(e) => setDates({ ...dates, from: e.target.value })} className="input input--xs" />}
                <input type="date" required aria-label={asOf ? 'As of' : 'To'} value={dates.to} onChange={(e) => setDates({ ...dates, to: e.target.value })} className="input input--xs" />
                <Button type="submit" variant="secondary">Show</Button>
            </form>
        </div>
    );
}
