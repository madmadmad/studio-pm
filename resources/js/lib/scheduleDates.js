// Date math for the project schedule. Dates are 'YYYY-MM-DD' strings
// (as the API sends them), worked in UTC so daylight-saving changes never
// shift a day.

const DAY = 24 * 60 * 60 * 1000;

export function toDay(value) {
    const [y, m, d] = String(value).slice(0, 10).split('-').map(Number);
    return Date.UTC(y, m - 1, d);
}

export function fromDay(ms) {
    return new Date(ms).toISOString().slice(0, 10);
}

// The first of each month from start to end, both inclusive ('YYYY-MM-01').
export function monthStarts(start, end) {
    const months = [];
    const s = new Date(toDay(start));
    let y = s.getUTCFullYear();
    let m = s.getUTCMonth() + (s.getUTCDate() === 1 ? 0 : 1);
    for (;;) {
        const day = fromDay(Date.UTC(y, m, 1));
        if (day > String(end).slice(0, 10)) return months;
        months.push(day);
        m += 1;
    }
}

const MONTH = new Intl.DateTimeFormat('en-US', { month: 'short', timeZone: 'UTC' });
const MONTH_YEAR = new Intl.DateTimeFormat('en-US', { month: 'short', year: 'numeric', timeZone: 'UTC' });

// "Oct", or "Jan 2027" with the year.
export function formatMonth(value, withYear = false) {
    return (withYear ? MONTH_YEAR : MONTH).format(new Date(toDay(value)));
}

// Inclusive: Oct 1 to Oct 1 is one day.
export function daysBetween(start, end) {
    return Math.round((toDay(end) - toDay(start)) / DAY) + 1;
}

export function addDays(value, days) {
    return fromDay(toDay(value) + days * DAY);
}

// The Monday on or before a date.
export function startOfWeek(value) {
    const dow = new Date(toDay(value)).getUTCDay(); // 0 = Sunday
    return addDays(value, -((dow + 6) % 7));
}

const SHORT = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
const SHORT_YEAR = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });

export function formatShort(value) {
    return SHORT.format(new Date(toDay(value)));
}

// "Oct 1 – 14, 2026", "Oct 28 – Nov 3, 2026", "Dec 29, 2026 – Jan 4, 2027",
// or just "Nov 2, 2026" for a one-day item.
export function formatRange(start, end) {
    if (start === end) return SHORT_YEAR.format(new Date(toDay(start)));
    const s = new Date(toDay(start));
    const e = new Date(toDay(end));
    if (s.getUTCFullYear() !== e.getUTCFullYear()) return `${SHORT_YEAR.format(s)} – ${SHORT_YEAR.format(e)}`;
    if (s.getUTCMonth() === e.getUTCMonth()) return `${SHORT.format(s)} – ${e.getUTCDate()}, ${e.getUTCFullYear()}`;
    return `${SHORT.format(s)} – ${SHORT_YEAR.format(e)}`;
}

// "1 day", "3 days", "2 weeks", "2 weeks 3 days".
export function formatLength(start, end) {
    const days = daysBetween(start, end);
    if (days < 7) return `${days} day${days === 1 ? '' : 's'}`;
    const weeks = Math.floor(days / 7);
    const rest = days % 7;
    return `${weeks} week${weeks === 1 ? '' : 's'}${rest ? ` ${rest} day${rest === 1 ? '' : 's'}` : ''}`;
}
