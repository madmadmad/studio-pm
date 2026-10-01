import { router } from '@inertiajs/react';
import { useState } from 'react';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import Button from './Button';
import MetricCard from './MetricCard';
import RowActions from './RowActions';
import TabToolbar from './TabToolbar';
import TimeEntryDrawer, { NewTimeEntryDrawer } from './TimeEntryDrawer';
import { TimeEntryStatusBadge } from './StatusBadges';
import { api } from '../lib/api';
import { formatDate } from '../lib/format';

// "Sep 28 – Oct 4, 2026"
function weekLabel(start, end) {
    const fmt = (value, opts) => new Date(`${value}T00:00:00`).toLocaleDateString('en-US', opts);
    const sameYear = start.slice(0, 4) === end.slice(0, 4);
    return `${fmt(start, { month: 'short', day: 'numeric', ...(sameYear ? {} : { year: 'numeric' }) })} – ${fmt(end, { month: 'short', day: 'numeric', year: 'numeric' })}`;
}

// "Monday, Sep 28"
function dayLabel(date) {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
}

function shiftWeek(start, days) {
    const d = new Date(`${start}T00:00:00`);
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const hoursText = (hours) => `${parseFloat(hours) || 0}h`;

// Moves to another week, reloading only the timesheet.
function goToWeek(week) {
    router.get('/profile', week ? { week } : {}, { only: ['timesheet'], preserveState: true, preserveScroll: true, replace: true });
}

function reloadTimesheet() {
    router.reload({ only: ['timesheet'] });
}

// One entry: its project and client, hours, service and task (or note),
// and status -- opening in the time-entry drawer.
function EntryRow({ entry, onOpen }) {
    return (
        <div onClick={() => onOpen(entry.id)} className="grid-row grid-row--action grid-row--link">
            <div className="timesheet__project">
                <span className="u-truncate">{entry.project?.name ?? 'No project'}</span>
                <span className="timesheet__client u-truncate">{entry.company?.name ?? '—'}</span>
            </div>
            <div className="timesheet__hours">{hoursText(entry.hours)}</div>
            <div className="timesheet__note">
                <span className="u-truncate">{[entry.service?.name, entry.task ? entry.task.title : entry.note].filter(Boolean).join(' · ') || '—'}</span>
            </div>
            <div className="timesheet__status"><TimeEntryStatusBadge entry={entry} /></div>
            <RowActions
                openLabel="Open entry"
                deleteLabel="Delete entry"
                confirmMessage={`Delete the ${entry.hours}h entry from ${formatDate(entry.date)}? This can't be undone.`}
                onDelete={entry.can_edit ? async () => {
                    await api.delete(`/api/time-entries/${entry.id}`);
                    reloadTimesheet();
                } : null}
            />
        </div>
    );
}

// The signed-in person's own time, a week at a time (the profile page's
// Timesheet tab): previous/next week, the week's totals as metric cards,
// then every day with its entries and hours. Entries open in the same
// drawer as a project's Time tab, editable while they're still theirs to
// change; "Log time" adds one. `timesheet` comes from ProfilePageController.
export default function Timesheet({ timesheet, projects, companies, services, currentUser }) {
    const [openId, setOpenId] = useState(null);
    const [logging, setLogging] = useState(false);
    const entries = timesheet.days.flatMap((day) => day.entries);
    const open = entries.find((e) => e.id === openId) || null;
    const { totals } = timesheet;

    return (
        <div>
            <div className="timesheet__week">
                <div className="timesheet__nav">
                    <button type="button" onClick={() => goToWeek(shiftWeek(timesheet.week_start, -7))} title="Previous week" aria-label="Previous week" className="btn btn--secondary timesheet__step">
                        <CaretLeft />
                    </button>
                    <span className="timesheet__range">{weekLabel(timesheet.week_start, timesheet.week_end)}</span>
                    <button type="button" onClick={() => goToWeek(shiftWeek(timesheet.week_start, 7))} title="Next week" aria-label="Next week" className="btn btn--secondary timesheet__step">
                        <CaretRight />
                    </button>
                    {!timesheet.is_this_week && <Button variant="secondary" onClick={() => goToWeek(null)}>This week</Button>}
                </div>
                <TabToolbar addLabel="Log time" onAdd={() => setLogging(true)} />
            </div>

            <div className="metric-grid">
                {/* The headline figure, in red. */}
                <MetricCard label={`Hours this week (${totals.entries})`} value={hoursText(totals.hours)} tone="primary" />
                <MetricCard label="Billable" value={hoursText(totals.billable)} />
                <MetricCard label="Non-billable" value={hoursText(Math.round((totals.hours - totals.billable) * 100) / 100)} />
            </div>

            <div className="card card--flush">
                <div className="grid-row grid-row--action grid-row--head">
                    <div className="timesheet__project">Project</div>
                    <div className="timesheet__hours">Hours</div>
                    <div className="timesheet__note">Service · Task / Note</div>
                    <div className="timesheet__status">Status</div>
                    <div />
                </div>
                {timesheet.days.map((day) => (
                    <div key={day.date} className="timesheet__day">
                        <div className="timesheet__day-head">
                            <span>{dayLabel(day.date)}</span>
                            <span className="timesheet__day-hours">{day.entries.length > 0 ? hoursText(day.hours) : 'No time'}</span>
                        </div>
                        {day.entries.map((entry) => <EntryRow key={entry.id} entry={entry} onOpen={setOpenId} />)}
                    </div>
                ))}
            </div>

            {open && (
                <TimeEntryDrawer
                    key={open.id}
                    entry={open}
                    tasks={open.project?.tasks || []}
                    services={services}
                    showContext
                    canEdit={open.can_edit}
                    onClose={() => setOpenId(null)}
                    onChange={reloadTimesheet}
                />
            )}

            {logging && (
                <NewTimeEntryDrawer
                    companies={companies}
                    projects={projects}
                    services={services}
                    currentUserId={currentUser.id}
                    onCreated={reloadTimesheet}
                    onClose={() => setLogging(false)}
                />
            )}
        </div>
    );
}
