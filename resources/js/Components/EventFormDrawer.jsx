import { useState } from 'react';
import { X } from '@phosphor-icons/react';
import AutoResizeTextarea from './AutoResizeTextarea';
import Button from './Button';
import Drawer from './Drawer';
import TitleInput from './TitleInput';
import Toggle from './Toggle';
import { api } from '../lib/api';
import { todayInAppTimezone } from '../lib/format';
import { addDays } from '../lib/scheduleDates';

const ZONE = 'America/New_York';
const dateIn = (iso) => new Intl.DateTimeFormat('en-CA', { timeZone: ZONE }).format(new Date(iso));
const timeIn = (iso) => new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: ZONE }).format(new Date(iso));

// "HH:MM" plus minutes, kept within the day (23:59 at most).
function addMinutes(time, minutes) {
    const [h, m] = time.split(':').map(Number);
    const total = Math.min(h * 60 + m + minutes, 23 * 60 + 59);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
const minutesOf = (time) => { const [h, m] = time.split(':').map(Number); return h * 60 + m; };

// The repeat choices, worded from the start date ("2026-11-17"). The
// server (GoogleCalendarController::recurrence) turns the same keys into
// Google's rule; a fifth weekday of the month is "the last".
const ORDINALS = ['first', 'second', 'third', 'fourth', 'last'];
function repeatOptions(startDate) {
    const date = new Date(`${startDate}T12:00:00`);
    if (Number.isNaN(date.getTime())) return [['none', 'Doesn’t repeat']];
    const weekday = date.toLocaleDateString('en-US', { weekday: 'long' });
    const day = date.getDate();
    const suffix = day % 10 === 1 && day !== 11 ? 'st' : day % 10 === 2 && day !== 12 ? 'nd' : day % 10 === 3 && day !== 13 ? 'rd' : 'th';
    return [
        ['none', 'Doesn’t repeat'],
        ['daily', 'Every day'],
        ['weekdays', 'Every weekday (Monday to Friday)'],
        ['weekly', `Every week on ${weekday}`],
        ['biweekly', `Every 2 weeks on ${weekday}`],
        ['monthly_day', `Every month on the ${day}${suffix}`],
        ['monthly_weekday', `Every month on the ${ORDINALS[Math.floor((day - 1) / 7)]} ${weekday}`],
        ['yearly', `Every year on ${date.toLocaleDateString('en-US', { month: 'long', day: 'numeric' })}`],
    ];
}

// The form's values for an event: Google's dates and moments as the
// date and time fields show them (an all-day event's last day, not
// Google's day after).
function fromEvent(event) {
    return {
        calendar_id: event.calendar_id,
        title: event.title === '(No title)' ? '' : event.title,
        all_day: event.all_day,
        start_date: event.all_day ? event.start : dateIn(event.start),
        start_time: event.all_day ? '09:00' : timeIn(event.start),
        end_date: event.all_day ? addDays(event.end, -1) : dateIn(new Date(new Date(event.end).getTime() - 1)),
        end_time: event.all_day ? '10:00' : timeIn(event.end),
        location: event.location ?? '',
        notes: event.notes ?? '',
        guests: (event.guests ?? []).map((g) => g.email),
        add_meet: false,
        project_id: event.project_id ?? '',
        ...NO_REPEAT,
    };
}

const NO_REPEAT = { repeat: 'none', repeat_ends: 'never', repeat_until: '', repeat_count: 10 };

// A new event on `date`: the next hour if that's today, 9 AM otherwise.
function blank(date, calendarId, projectId) {
    const now = timeIn(new Date().toISOString());
    const start = date === todayInAppTimezone() && now < '23:00' ? `${String(Number(now.slice(0, 2)) + 1).padStart(2, '0')}:00` : '09:00';
    return {
        calendar_id: calendarId, title: '', all_day: false,
        start_date: date, start_time: start, end_date: date, end_time: addMinutes(start, 60),
        location: '', notes: '', guests: [], add_meet: false,
        project_id: projectId ?? '',
        ...NO_REPEAT,
    };
}

const TIME_FIELDS = ['all_day', 'start_date', 'start_time', 'end_date', 'end_time'];

// Adding or changing a Google Calendar event (Home → Agenda). A change
// sends only the fields that changed, checked against the version that
// was opened; a repeat asks whether it's this event or all of them.
export default function EventFormDrawer({ event = null, date, calendars, defaultCalendarId = null, projects = [], projectId = null, onSaved, onConflict, onClose }) {
    const editable = calendars.filter((c) => c.can_edit);
    const [initial] = useState(() => (event ? fromEvent(event) : blank(date, editable.find((c) => c.id === defaultCalendarId)?.id ?? editable[0]?.id ?? '', projectId)));
    // The open projects, plus the one it's linked to if that's since closed.
    const projectChoices = event?.project && !projects.some((p) => p.id === event.project.id) ? [event.project, ...projects] : projects;
    const [form, setForm] = useState(initial);
    const [guestDraft, setGuestDraft] = useState('');
    const [scope, setScope] = useState('this');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const repeat = Boolean(event?.recurring_event_id);

    const set = (changes) => setForm((f) => ({ ...f, ...changes }));

    // Moving the start keeps the length: the end moves with it.
    function setStart(changes) {
        setForm((f) => {
            const next = { ...f, ...changes };
            const days = Math.round((new Date(f.end_date) - new Date(f.start_date)) / 86400000);
            next.end_date = addDays(next.start_date, Math.max(days, 0));
            if (!f.all_day) {
                const length = (days * 1440) + minutesOf(f.end_time) - minutesOf(f.start_time);
                const endMinutes = minutesOf(next.start_time) + Math.max(length, 0);
                next.end_date = addDays(next.start_date, Math.floor(endMinutes / 1440));
                next.end_time = addMinutes('00:00', endMinutes % 1440);
            }
            return next;
        });
    }

    // A typed address becomes a guest on Enter, a comma, or leaving the field.
    function addGuests(text) {
        const emails = text.split(/[\s,;]+/).map((e) => e.trim()).filter((e) => /^\S+@\S+\.\S+$/.test(e));
        if (emails.length) set({ guests: [...new Set([...form.guests, ...emails])] });
        setGuestDraft('');
    }

    async function save(e) {
        e.preventDefault();
        if (guestDraft.trim()) addGuests(guestDraft);
        const guests = guestDraft.trim()
            ? [...new Set([...form.guests, ...guestDraft.split(/[\s,;]+/).filter((x) => /^\S+@\S+\.\S+$/.test(x))])]
            : form.guests;
        // Only the end that was chosen goes with a repeat.
        const ends = form.repeat === 'none' ? 'never' : form.repeat_ends;
        const repeatValues = {
            repeat: form.repeat,
            repeat_ends: ends,
            repeat_until: ends === 'on' ? form.repeat_until : null,
            repeat_count: ends === 'after' ? Number(form.repeat_count) : null,
        };
        const { repeat: _r, repeat_ends: _e, repeat_until: _u, repeat_count: _c, ...rest } = form;
        const values = { ...rest, guests, project_id: rest.project_id ? Number(rest.project_id) : null };
        setSaving(true);
        setError('');
        try {
            let saved;
            if (!event) {
                ({ event: saved } = await api.post('/api/google-calendar/events', { ...values, ...repeatValues }));
            } else {
                const changed = {};
                for (const key of ['title', 'location', 'notes']) if (values[key] !== initial[key]) changed[key] = values[key];
                if (JSON.stringify(values.guests) !== JSON.stringify(initial.guests)) changed.guests = values.guests;
                if (values.project_id !== (initial.project_id ? Number(initial.project_id) : null)) changed.project_id = values.project_id;
                // Only a one-off event can be made to repeat here (a series
                // keeps Google's rule). The rule is worded from the start
                // date, so the dates go with it.
                const repeatChanged = !repeat && form.repeat !== 'none';
                if (repeatChanged) Object.assign(changed, repeatValues);
                if (repeatChanged || TIME_FIELDS.some((key) => values[key] !== initial[key])) TIME_FIELDS.forEach((key) => { changed[key] = values[key]; });
                if (values.add_meet) changed.add_meet = true;
                if (Object.keys(changed).length === 0) { onClose(); return; }
                ({ event: saved } = await api.patch(`/api/google-calendar/events/${encodeURIComponent(event.id)}`, {
                    ...changed,
                    calendar_id: event.calendar_id,
                    etag: event.etag,
                    scope: repeat ? scope : 'this',
                    recurring_event_id: event.recurring_event_id,
                    split_at: event.split_at ?? event.start,
                    original_start: event.start,
                    original_end: event.end,
                }));
            }
            onSaved(saved);
            onClose();
        } catch (err) {
            if (err.status === 409) {
                onConflict(err.message);
                onClose();
                return;
            }
            setError(err.message || 'Couldn’t save this event.');
        } finally {
            setSaving(false);
        }
    }

    if (!event && editable.length === 0) {
        return (
            <Drawer onClose={onClose}>
                <h2 className="drawer__title">New event</h2>
                <p className="drawer__empty">None of the calendars you show can take new events. Choose one you can edit on your profile.</p>
            </Drawer>
        );
    }

    return (
        <Drawer onClose={onClose}>
            <form onSubmit={save}>
                <TitleInput
                    autoFocus
                    value={form.title}
                    onChange={(e) => set({ title: e.target.value })}
                    placeholder={event ? 'Event title' : 'New event'}
                    aria-label="Title"
                    className="inline-edit inline-edit--title"
                />

                <div className="drawer__section drawer__section--divided">
                    <div className="section-label section-label--tight">Calendar</div>
                    {event ? (
                        <div className="drawer__text">{event.calendar}</div>
                    ) : (
                        <select value={form.calendar_id} onChange={(e) => set({ calendar_id: e.target.value })} className="input input--xs">
                            {editable.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    )}
                </div>

                <div className="drawer__section">
                    <div className="section-label section-label--tight">Project</div>
                    <select value={form.project_id} onChange={(e) => set({ project_id: e.target.value })} className="input input--xs" aria-label="Project">
                        <option value="">None</option>
                        {projectChoices.map((p) => <option key={p.id} value={p.id}>{p.name}{p.client ? ` (${p.client})` : ''}</option>)}
                    </select>
                    {form.project_id !== '' && <p className="form-hint form-hint--attached">Shows on the project’s Schedule.</p>}
                </div>

                <div className="drawer__section">
                    <Toggle checked={form.all_day} onChange={(all_day) => set({ all_day })} label="All day" />
                </div>

                <div className="form-grid drawer__section">
                    <div>
                        <div className="section-label section-label--tight">Starts</div>
                        <div className="event-form__when">
                            <input required type="date" value={form.start_date} onChange={(e) => setStart({ start_date: e.target.value })} className="input input--xs" aria-label="Start date" />
                            {!form.all_day && <input required type="time" value={form.start_time} onChange={(e) => setStart({ start_time: e.target.value })} className="input input--xs" aria-label="Start time" />}
                        </div>
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Ends</div>
                        <div className="event-form__when">
                            <input required type="date" min={form.start_date} value={form.end_date} onChange={(e) => set({ end_date: e.target.value })} className="input input--xs" aria-label="End date" />
                            {!form.all_day && <input required type="time" value={form.end_time} onChange={(e) => set({ end_time: e.target.value })} className="input input--xs" aria-label="End time" />}
                        </div>
                    </div>
                </div>

                {!repeat && (
                    <div className="drawer__section event-form__repeat">
                        <div>
                            <div className="section-label section-label--tight">Repeat</div>
                            <select value={form.repeat} onChange={(e) => set({ repeat: e.target.value })} className="input input--xs" aria-label="Repeat">
                                {repeatOptions(form.start_date).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </div>
                        {form.repeat !== 'none' && (
                            <div>
                                <div className="section-label section-label--tight">Ends</div>
                                <div className="event-form__when">
                                    <select
                                        value={form.repeat_ends}
                                        onChange={(e) => set({
                                            repeat_ends: e.target.value,
                                            // Three months out to start with.
                                            repeat_until: form.repeat_until || addDays(form.start_date, 91),
                                        })}
                                        className="input input--xs"
                                        aria-label="Repeat ends"
                                    >
                                        <option value="never">Never</option>
                                        <option value="on">On</option>
                                        <option value="after">After</option>
                                    </select>
                                    {form.repeat_ends === 'on' && (
                                        <input required type="date" min={form.start_date} value={form.repeat_until} onChange={(e) => set({ repeat_until: e.target.value })} className="input input--xs" aria-label="Last day" />
                                    )}
                                    {form.repeat_ends === 'after' && (
                                        <span className="event-form__count">
                                            <input required type="number" min={2} max={500} value={form.repeat_count} onChange={(e) => set({ repeat_count: e.target.value })} className="input input--xs" aria-label="Number of times" />
                                            times
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <div className="drawer__section">
                    <div className="section-label section-label--tight">Location</div>
                    <input value={form.location} onChange={(e) => set({ location: e.target.value })} placeholder="Add a place or address" className="input input--xs" />
                </div>

                <div className="drawer__section">
                    <div className="section-label section-label--tight">Guests</div>
                    {form.guests.length > 0 && (
                        <ul className="event-form__guests">
                            {form.guests.map((email) => {
                                const reply = event?.guests?.find((g) => g.email.toLowerCase() === email.toLowerCase())?.response;
                                return (
                                    <li key={email} className="event-form__guest">
                                        <span className="u-truncate">{email}</span>
                                        {reply && reply !== 'needsAction' && <span className="event-form__reply">{reply === 'accepted' ? 'Going' : reply === 'declined' ? 'Not going' : 'Maybe'}</span>}
                                        <button type="button" onClick={() => set({ guests: form.guests.filter((g) => g !== email) })} title={`Remove ${email}`} aria-label={`Remove ${email}`} className="icon-btn">
                                            <X />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <input
                        type="email"
                        multiple
                        value={guestDraft}
                        onChange={(e) => setGuestDraft(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addGuests(guestDraft); } }}
                        onBlur={() => guestDraft.trim() && addGuests(guestDraft)}
                        placeholder="Add guests by email"
                        className="input input--xs"
                    />
                    {form.guests.length > 0 && <p className="form-hint form-hint--attached">Google emails them the invitation{event ? ' and any changes' : ''}.</p>}
                </div>

                {!event?.has_meet && (
                    <div className="drawer__section">
                        <Toggle checked={form.add_meet} onChange={(add_meet) => set({ add_meet })} label="Add a Google Meet video call" />
                    </div>
                )}

                <div className="drawer__section">
                    <div className="section-label">Notes</div>
                    {/* Five lines to start (room for an agenda), growing past that. */}
                    <AutoResizeTextarea rows={5} value={form.notes} onChange={(e) => set({ notes: e.target.value })} placeholder="Agenda, links, anything guests should know" className="input" />
                </div>

                {repeat && (
                    <div className="drawer__section">
                        <div className="section-label section-label--tight">This is a repeating event</div>
                        <div className="event-form__scope" role="radiogroup" aria-label="Change">
                            <label><input type="radio" name="scope" value="this" checked={scope === 'this'} onChange={() => setScope('this')} /> Change only this event</label>
                            <label><input type="radio" name="scope" value="following" checked={scope === 'following'} onChange={() => setScope('following')} /> Change this and following events</label>
                            <label><input type="radio" name="scope" value="all" checked={scope === 'all'} onChange={() => setScope('all')} /> Change all events</label>
                        </div>
                    </div>
                )}

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="confirm" disabled={saving || !form.title.trim() || !form.calendar_id}>{event ? 'Save' : 'Add event'}</Button>
                </div>
            </form>
        </Drawer>
    );
}
