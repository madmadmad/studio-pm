const TIME_ZONE = 'America/New_York';

// No date-timezone library is installed in this app -- this derives the
// America/New_York UTC offset at a given instant from Intl directly rather
// than pulling one in.
function offsetMinutesAt(date) {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: TIME_ZONE, timeZoneName: 'shortOffset' }).formatToParts(date);
    const tzName = parts.find((p) => p.type === 'timeZoneName')?.value || 'GMT+0';
    const match = tzName.match(/GMT([+-]\d+)(?::(\d+))?/);
    if (!match) return 0;
    const hours = parseInt(match[1], 10);
    const minutes = match[2] ? parseInt(match[2], 10) : 0;
    return hours * 60 + (hours < 0 ? -minutes : minutes);
}

// Converts wall-clock `date` ('YYYY-MM-DD') + `time` ('HH:mm') meant as
// America/New_York into a UTC ISO string, for scheduling a send. One
// offset-correction pass, which is exact outside the one-hour DST
// transition window itself.
export function easternWallTimeToUtcIso(date, time) {
    const naiveUtcGuess = new Date(`${date}T${time}:00Z`);
    const offsetMinutes = offsetMinutesAt(naiveUtcGuess);

    return new Date(naiveUtcGuess.getTime() - offsetMinutes * 60000).toISOString();
}

// Splits a UTC value into the {date, time} wall-clock components an
// America/New_York viewer would see -- for populating the schedule picker
// when editing an existing scheduled send.
export function utcToEasternParts(value) {
    const date = new Date(value);
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(date);
    const get = (type) => parts.find((p) => p.type === type)?.value;

    return { date: `${get('year')}-${get('month')}-${get('day')}`, time: `${get('hour')}:${get('minute')}` };
}

// Schedule times are always shown in America/New_York, regardless of the
// viewer's own browser timezone.
export function formatDateTimeEastern(value) {
    if (!value) return '—';

    const formatted = new Intl.DateTimeFormat('en-US', {
        timeZone: TIME_ZONE,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));

    return `${formatted} ET`;
}
