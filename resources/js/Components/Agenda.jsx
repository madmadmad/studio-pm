import { Fragment, useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowSquareOut, CaretLeft, CaretRight, CheckSquare, MapPin, PencilSimple, Plus, Square, Trash, VideoCamera } from '@phosphor-icons/react';
import Button from './Button';
import Drawer from './Drawer';
import EventFormDrawer from './EventFormDrawer';
import ViewToggle from './ViewToggle';
import { api } from '../lib/api';
import { todayInAppTimezone } from '../lib/format';
import { addDays, formatRange, startOfWeek } from '../lib/scheduleDates';

const ZONE = 'America/New_York';
const VIEWS = [
    { value: 'day', label: 'Day', days: 1 },
    { value: '3day', label: '3 days', days: 3 },
    { value: 'week', label: 'Week', days: 7 },
];

// The date (YYYY-MM-DD) and clock time of a moment, in the app's time zone.
const dateIn = (iso) => new Intl.DateTimeFormat('en-CA', { timeZone: ZONE }).format(new Date(iso));
const TIME = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', timeZone: ZONE });
const DAY_HEAD = new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });
// A day's heading bar: "Tuesday, November 17".
const DAY_BAR = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' });
const DAY_LONG = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' });

// "9:00 – 10:30 AM", or "11:00 AM – 1:00 PM" across noon.
// (By parts: browsers put a narrow no-break space before AM/PM.)
function timeRange(startIso, endIso) {
    const parts = (iso) => {
        const p = TIME.formatToParts(new Date(iso));
        const clock = p.filter((x) => ['hour', 'literal', 'minute'].includes(x.type)).map((x) => x.value).join('').trim();
        return { clock, period: p.find((x) => x.type === 'dayPeriod')?.value ?? '' };
    };
    const [s, e] = [parts(startIso), parts(endIso)];
    return s.period === e.period ? `${s.clock} – ${e.clock} ${e.period}` : `${s.clock} ${s.period} – ${e.clock} ${e.period}`;
}

const dayDate = (date) => new Date(`${date}T00:00:00Z`);

// What one day of the agenda holds: all-day events, tasks due, then timed
// events by start. An event over several days shows on each of them.
function itemsOn(date, events, tasks) {
    const allDay = events.filter((e) => e.all_day && e.start <= date && date < e.end);
    const timed = events
        .filter((e) => !e.all_day && dateIn(e.start) <= date && date <= dateIn(new Date(new Date(e.end).getTime() - 1)))
        .sort((a, b) => a.start.localeCompare(b.start));
    const due = tasks.filter((t) => t.due_date?.slice(0, 10) === date);
    return { allDay, due, timed };
}

// When a timed event happens on this day: its times, or how it runs over
// from or into another day.
function timeLabel(event, date) {
    const startDay = dateIn(event.start);
    const endDay = dateIn(new Date(new Date(event.end).getTime() - 1));
    if (startDay === date && endDay === date) return timeRange(event.start, event.end);
    if (startDay === date) return `From ${TIME.format(new Date(event.start))}`;
    if (endDay === date) return `Until ${TIME.format(new Date(event.end))}`;
    return 'All day';
}

// Home → Agenda: your Google calendars (the ones you chose on Profile)
// and the tasks assigned to you or starred, by day. No project schedules:
// this is your own calendar. Day, 3 days (the default) or a week.
export default function Agenda({ tasks }) {
    const today = todayInAppTimezone();
    const [view, setView] = useState('3day');
    const [anchor, setAnchor] = useState(today);
    const [data, setData] = useState(null); // the events endpoint's answer
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState(null);
    const [editing, setEditing] = useState(null); // { event } to change one, { date } for a new one
    const [reload, setReload] = useState(0);
    const [notice, setNotice] = useState('');

    const days = VIEWS.find((v) => v.value === view).days;
    const start = view === 'week' ? startOfWeek(anchor) : anchor;
    const end = addDays(start, days - 1);
    const dates = Array.from({ length: days }, (_, i) => addDays(start, i));
    const showsToday = today >= start && today <= end;

    useEffect(() => {
        let current = true;
        setLoading(true);
        api.get(`/api/google-calendar/events?start=${start}&days=${days}`)
            .then((answer) => { if (current) setData(answer); })
            .catch(() => { if (current) setData({ connected: true, events: [], error: 'Your Google calendar couldn’t be loaded just now.' }); })
            .finally(() => { if (current) setLoading(false); });
        return () => { current = false; };
    }, [start, days, reload]);

    const refresh = () => setReload((n) => n + 1);
    const canAdd = data?.connected && (data.calendars ?? []).some((c) => c.can_edit);

    const events = data?.events ?? [];
    const myTasks = tasks.filter((t) => t.is_assigned || t.is_favorite);

    return (
        <div className="agenda">
            <div className="agenda__toolbar">
                <div className="agenda__nav">
                    <button type="button" onClick={() => setAnchor(addDays(anchor, -days))} title="Earlier" aria-label="Earlier" className="btn btn--secondary agenda__step">
                        <CaretLeft />
                    </button>
                    <span className="agenda__range">{days === 1 ? DAY_LONG.format(dayDate(start)) : formatRange(start, end)}</span>
                    <button type="button" onClick={() => setAnchor(addDays(anchor, days))} title="Later" aria-label="Later" className="btn btn--secondary agenda__step">
                        <CaretRight />
                    </button>
                    {!showsToday && <Button variant="secondary" onClick={() => setAnchor(today)}>Today</Button>}
                    {loading && <span className="agenda__loading">Loading…</span>}
                </div>
                <ViewToggle views={VIEWS} value={view} onChange={setView} />
                {canAdd && (
                    <button type="button" onClick={() => setEditing({ date: showsToday ? today : start })} title="New event" aria-label="New event" className="icon-btn icon-btn--secondary icon-btn--lg">
                        <Plus />
                    </button>
                )}
            </div>

            {notice && <p className="agenda__notice form-message form-message--error">{notice}</p>}

            {data && !data.connected && (
                <p className="agenda__notice">
                    {data.lost ? 'Google Calendar access was removed in your Google account. ' : 'Your Google calendar isn’t connected, so only your tasks show here. '}
                    <Link href="/profile" className="link link--inline">Connect it from your profile</Link>.
                </p>
            )}
            {data?.connected && data.calendars?.length === 0 && (
                <p className="agenda__notice">
                    No calendars chosen yet. <Link href="/profile" className="link link--inline">Choose which to show on your profile</Link>.
                </p>
            )}
            {data?.error && <p className="agenda__notice form-message form-message--error">{data.error} Your tasks are still here.</p>}

            {/* One list, a day's heading bar (as the invoice months') over its rows. */}
            <div className="card card--flush">
                {dates.map((date) => {
                    const { allDay, due, timed } = itemsOn(date, events, myTasks);
                    const empty = !allDay.length && !due.length && !timed.length;
                    const label = date === today ? 'Today' : date === addDays(today, 1) ? 'Tomorrow' : null;
                    return (
                        <Fragment key={date}>
                            <h3 className="month-divider__bar agenda__day-head">
                                <span className="month-divider__label">{DAY_BAR.format(dayDate(date))}</span>
                                {label && <span className="month-divider__figures">{label}</span>}
                            </h3>
                            {empty && <div className="agenda__empty">Nothing scheduled</div>}
                            {allDay.map((event) => (
                                <EventRow key={`${event.calendar_id}:${event.id}`} event={event} time="All day" onOpen={() => setOpen({ event, date })} />
                            ))}
                            {due.map((task) => (
                                <TaskRow key={`task:${task.id}`} task={task} />
                            ))}
                            {timed.map((event) => (
                                <EventRow key={`${event.calendar_id}:${event.id}`} event={event} time={timeLabel(event, date)} onOpen={() => setOpen({ event, date })} />
                            ))}
                        </Fragment>
                    );
                })}
            </div>

            {open && (
                <EventDrawer
                    event={open.event}
                    onEdit={() => { setEditing({ event: open.event }); setOpen(null); }}
                    onDeleted={() => { setOpen(null); refresh(); }}
                    onConflict={(message) => { setOpen(null); setNotice(message); refresh(); }}
                    onClose={() => setOpen(null)}
                />
            )}
            {editing && (
                <EventFormDrawer
                    event={editing.event}
                    date={editing.date}
                    calendars={data?.calendars ?? []}
                    defaultCalendarId={data?.default_calendar_id}
                    onSaved={() => { setNotice(''); refresh(); }}
                    onConflict={(message) => { setNotice(message); refresh(); }}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

// A Google event: its time, the calendar's color, title, where, and a
// Join link straight to the call. The row opens its details.
function EventRow({ event, time, onOpen }) {
    return (
        <div role="button" tabIndex={0} onClick={onOpen} onKeyDown={(e) => { if (e.key === 'Enter') onOpen(); }} className="list-row agenda__item">
            <div className="agenda__time">{time}</div>
            <span className="agenda__swatch" style={{ backgroundColor: event.color || undefined }} aria-hidden="true" />
            <div className="agenda__body">
                <div className="agenda__title">{event.title}</div>
                <div className="agenda__meta">
                    {event.calendar}
                    {event.location && <> · {event.location}</>}
                </div>
            </div>
            {event.join_url && (
                <a href={event.join_url} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()} className="agenda__join">
                    <VideoCamera aria-hidden="true" /> Join
                </a>
            )}
        </div>
    );
}

// A task due that day, assigned to you or starred. Opens on its project.
function TaskRow({ task }) {
    const done = task.status === 'done';
    const Icon = done ? CheckSquare : Square;
    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => router.visit(`/projects/${task.project_id}?tab=Tasks&task=${task.id}`)}
            onKeyDown={(e) => { if (e.key === 'Enter') router.visit(`/projects/${task.project_id}?tab=Tasks&task=${task.id}`); }}
            className={`list-row agenda__item agenda__item--task${done ? ' agenda__item--done' : ''}`}
        >
            <div className="agenda__time">Task due</div>
            <Icon weight="fill" className="agenda__task-icon" aria-hidden="true" />
            <div className="agenda__body">
                <div className="agenda__title">{task.title}</div>
                <div className="agenda__meta">
                    {task.project?.name}
                    {task.project?.company && <> · {task.project.company.name}</>}
                </div>
            </div>
        </div>
    );
}

// An event's details, with Edit and Delete when it's on a calendar they
// can change. Deleting a repeat asks: this one, or the whole series.
function EventDrawer({ event, onEdit, onDeleted, onConflict, onClose }) {
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState('');

    async function remove(scope) {
        setDeleting(true);
        setError('');
        const query = new URLSearchParams({ calendar_id: event.calendar_id, scope, ...(event.etag ? { etag: event.etag } : {}), ...(event.recurring_event_id ? { recurring_event_id: event.recurring_event_id } : {}) });
        try {
            await api.delete(`/api/google-calendar/events/${encodeURIComponent(event.id)}?${query}`);
            onDeleted();
        } catch (err) {
            if (err.status === 409) { onConflict(err.message); return; }
            setError(err.message || 'Couldn’t delete this event.');
            setDeleting(false);
        }
    }

    const when = event.all_day
        ? (addDays(event.start, 1) === event.end ? DAY_LONG.format(dayDate(event.start)) : `${formatRange(event.start, addDays(event.end, -1))} · All day`)
        : dateIn(event.start) === dateIn(new Date(new Date(event.end).getTime() - 1))
            ? `${DAY_LONG.format(dayDate(dateIn(event.start)))} · ${timeRange(event.start, event.end)}`
            : `${DAY_HEAD.format(dayDate(dateIn(event.start)))}, ${TIME.format(new Date(event.start))} – ${DAY_HEAD.format(dayDate(dateIn(event.end)))}, ${TIME.format(new Date(event.end))}`;

    return (
        <Drawer
            onClose={onClose}
            actions={event.can_edit && (
                <>
                    <button type="button" onClick={onEdit} title="Edit event" aria-label="Edit event" className="icon-btn drawer__action">
                        <PencilSimple />
                    </button>
                    <button type="button" onClick={() => setConfirming(true)} title="Delete event" aria-label="Delete event" className="icon-btn icon-btn--danger drawer__action">
                        <Trash />
                    </button>
                </>
            )}
        >
            <h2 className="drawer__title">{event.title}</h2>
            {confirming && (
                <div className="drawer__section agenda-event__confirm">
                    <p>{event.recurring_event_id ? 'Delete this repeating event?' : 'Delete this event?'}{event.guests?.length ? ' Guests are told it’s cancelled.' : ''}</p>
                    <div className="form-actions">
                        <Button variant="secondary" onClick={() => setConfirming(false)} disabled={deleting}>Keep it</Button>
                        {event.recurring_event_id && <Button variant="danger" onClick={() => remove('all')} disabled={deleting}>All events</Button>}
                        <Button variant="danger" onClick={() => remove('this')} disabled={deleting}>{event.recurring_event_id ? 'Only this one' : 'Delete'}</Button>
                    </div>
                    {error && <div className="form-message form-message--error">{error}</div>}
                </div>
            )}
            <div className="drawer__section">
                <div className="agenda-event__when">{when}</div>
                <div className="agenda-event__calendar">
                    <span className="agenda__swatch" style={{ backgroundColor: event.color || undefined }} aria-hidden="true" />
                    {event.calendar}
                </div>
            </div>
            {(event.join_url || event.location) && (
                <div className="drawer__section agenda-event__links">
                    {event.join_url && (
                        <a href={event.join_url} target="_blank" rel="noopener noreferrer" className="btn btn--confirm">
                            <VideoCamera aria-hidden="true" /> Join meeting
                        </a>
                    )}
                    {event.location && (
                        <div className="agenda-event__location"><MapPin aria-hidden="true" /> {event.location}</div>
                    )}
                </div>
            )}
            {event.description && (
                <div className="drawer__section">
                    <div className="section-label">Notes</div>
                    <div className="prose agenda-event__notes" dangerouslySetInnerHTML={{ __html: event.description }} />
                </div>
            )}
            {event.google_url && (
                <div className="drawer__section">
                    <a href={event.google_url} target="_blank" rel="noopener noreferrer" className="link link--muted agenda-event__google">
                        Open in Google Calendar <ArrowSquareOut aria-hidden="true" />
                    </a>
                </div>
            )}
        </Drawer>
    );
}
