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
    };
}

// A new event on `date`: the next hour if that's today, 9 AM otherwise.
function blank(date, calendarId) {
    const now = timeIn(new Date().toISOString());
    const start = date === todayInAppTimezone() && now < '23:00' ? `${String(Number(now.slice(0, 2)) + 1).padStart(2, '0')}:00` : '09:00';
    return {
        calendar_id: calendarId, title: '', all_day: false,
        start_date: date, start_time: start, end_date: date, end_time: addMinutes(start, 60),
        location: '', notes: '', guests: [], add_meet: false,
    };
}

const TIME_FIELDS = ['all_day', 'start_date', 'start_time', 'end_date', 'end_time'];

// Adding or changing a Google Calendar event (Home → Agenda). A change
// sends only the fields that changed, checked against the version that
// was opened; a repeat asks whether it's this event or all of them.
export default function EventFormDrawer({ event = null, date, calendars, defaultCalendarId = null, onSaved, onConflict, onClose }) {
    const editable = calendars.filter((c) => c.can_edit);
    const [initial] = useState(() => (event ? fromEvent(event) : blank(date, editable.find((c) => c.id === defaultCalendarId)?.id ?? editable[0]?.id ?? '')));
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
        const values = { ...form, guests };
        setSaving(true);
        setError('');
        try {
            let saved;
            if (!event) {
                ({ event: saved } = await api.post('/api/google-calendar/events', values));
            } else {
                const changed = {};
                for (const key of ['title', 'location', 'notes']) if (values[key] !== initial[key]) changed[key] = values[key];
                if (JSON.stringify(values.guests) !== JSON.stringify(initial.guests)) changed.guests = values.guests;
                if (TIME_FIELDS.some((key) => values[key] !== initial[key])) TIME_FIELDS.forEach((key) => { changed[key] = values[key]; });
                if (values.add_meet) changed.add_meet = true;
                if (Object.keys(changed).length === 0) { onClose(); return; }
                ({ event: saved } = await api.patch(`/api/google-calendar/events/${encodeURIComponent(event.id)}`, {
                    ...changed,
                    calendar_id: event.calendar_id,
                    etag: event.etag,
                    scope: repeat ? scope : 'this',
                    recurring_event_id: event.recurring_event_id,
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
