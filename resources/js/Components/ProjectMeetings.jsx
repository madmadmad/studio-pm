import { useEffect, useState } from 'react';
import { Plus } from '@phosphor-icons/react';
import { EventDrawer, EventRow, timeRange } from './Agenda';
import EventFormDrawer from './EventFormDrawer';
import { api } from '../lib/api';
import { todayInAppTimezone } from '../lib/format';

const ZONE = 'America/New_York';
const DAY = new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone: ZONE });
const DAY_OF_DATE = new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });

// When, in the time column: the day, then the times (or "All day"), on
// one line on a desktop and two on a phone.
function when(event) {
    const day = event.all_day ? DAY_OF_DATE.format(new Date(`${event.start}T00:00:00Z`)) : DAY.format(new Date(event.start));
    const hours = event.all_day ? 'All day' : timeRange(event.start, event.end);
    return <><span className="project-meetings__day">{day}</span> <span className="project-meetings__hours">{hours}</span></>;
}

// A project's Schedule tab: its upcoming meetings, the Google events
// linked to it on the calendars you show (Home → Agenda's, by Project on
// the event). Opens, edits and adds them as the Agenda does. Hidden until
// your Google Calendar is connected.
export default function ProjectMeetings({ project }) {
    const [data, setData] = useState(null);
    const [reload, setReload] = useState(0);
    const [open, setOpen] = useState(null);
    const [editing, setEditing] = useState(null); // { event } or { date } for a new one
    const [notice, setNotice] = useState('');

    useEffect(() => {
        let current = true;
        api.get(`/api/projects/${project.id}/meetings`)
            .then((answer) => { if (current) setData(answer); })
            .catch(() => { if (current) setData({ connected: true, events: [], error: 'Meetings couldn’t be loaded from Google just now.' }); });
        return () => { current = false; };
    }, [project.id, reload]);

    if (!data?.connected) return null;

    const refresh = () => setReload((n) => n + 1);
    const canAdd = (data.calendars ?? []).some((c) => c.can_edit);
    const events = data.events ?? [];

    return (
        <div className="page-section project-meetings">
            {(notice || data.error) && <p className="agenda__notice form-message form-message--error">{notice || data.error}</p>}
            <div className="card card--flush">
                {/* The Agenda's day bar (the invoice months'), the add button at the right. */}
                <h3 className="month-divider__bar agenda__day-head">
                    <span className="month-divider__label">Upcoming meetings</span>
                    {canAdd && (
                        <button type="button" onClick={() => setEditing({ date: todayInAppTimezone() })} title="New meeting" aria-label="New meeting" className="icon-btn project-meetings__add">
                            <Plus />
                        </button>
                    )}
                </h3>
                {events.length === 0 && (
                    <div className="agenda__empty">
                        No meetings linked to this project. Choose the project on an event{canAdd ? ', or add one with +' : ''}.
                    </div>
                )}
                {/* On the project's own page the project goes without saying: the row shows its calendar. */}
                {events.map((event) => (
                    <EventRow key={`${event.calendar_id}:${event.id}`} event={{ ...event, project: null }} time={when(event)} onOpen={() => setOpen(event)} />
                ))}
            </div>

            {open && (
                <EventDrawer
                    event={open}
                    onEdit={() => { setEditing({ event: open }); setOpen(null); }}
                    onDeleted={() => { setOpen(null); refresh(); }}
                    onConflict={(message) => { setOpen(null); setNotice(message); refresh(); }}
                    onClose={() => setOpen(null)}
                />
            )}
            {editing && (
                <EventFormDrawer
                    event={editing.event}
                    date={editing.date}
                    calendars={data.calendars ?? []}
                    defaultCalendarId={data.default_calendar_id}
                    projects={data.projects ?? []}
                    projectId={project.id}
                    onSaved={() => { setNotice(''); refresh(); }}
                    onConflict={(message) => { setNotice(message); refresh(); }}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}
