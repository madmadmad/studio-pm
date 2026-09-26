import { useEffect, useState } from 'react';
import { Trash } from '@phosphor-icons/react';
import AutoResizeTextarea from './AutoResizeTextarea';
import Button from './Button';
import Drawer, { DrawerByline, DrawerDate } from './Drawer';
import { TimeEntryStatusBadge } from './StatusBadges';
import { api } from '../lib/api';
import { todayLocal } from '../lib/paymentTerms';

// Time entries in the drawer, shared by the Time page and a project's Time
// tab so both open and log time the same way.

// An entry: team member, date, hours, service, task, note -- each saved as
// it changes. Whether it's billable comes from its service (shown under
// the Service picker and as the status badge), not a separate setting. `tasks` are the entry's project's tasks. `showContext`
// adds its client and project (the Time page lists every project's time; a
// project's own tab doesn't need it). `canEdit` (a manager, or whoever
// logged it -- the API's rule) makes the fields editable and shows delete.
// `teamMembers` (the project's team) lets a manager reassign the entry;
// without it the team member is shown read-only. `services` ({ id, name,
// billable }) are what the entry can be for.
export default function TimeEntryDrawer({ entry, tasks, services = [], teamMembers = null, showContext = false, canEdit = true, onClose, onChange }) {
    const [hours, setHours] = useState('');
    const [note, setNote] = useState('');

    useEffect(() => {
        if (entry) {
            setHours(entry.hours);
            setNote(entry.note ?? '');
        }
    }, [entry?.id]);

    async function updateField(field, value) {
        await api.patch(`/api/time-entries/${entry.id}`, { [field]: value });
        onChange();
    }

    async function remove() {
        if (!confirm(`Delete this ${entry.hours}h entry? This can't be undone.`)) return;
        await api.delete(`/api/time-entries/${entry.id}`);
        onClose();
        onChange();
    }

    const disabled = !canEdit;

    return (
        <Drawer
            onClose={onClose}
            actions={canEdit && (
                <button onClick={remove} title="Delete entry" className="icon-btn icon-btn--danger drawer__action">
                    <Trash />
                </button>
            )}
        >
            <DrawerByline>
                <DrawerDate label="Created" date={entry.created_at} />
                <TimeEntryStatusBadge entry={entry} />
            </DrawerByline>
            {/* An entry has no name of its own -- it's titled by its task. */}
            <h2 className="drawer__title">{entry.task?.title || 'Time entry'}</h2>

            {showContext && (
                <div className="form-grid drawer__section drawer__section--divided">
                    <div>
                        <div className="section-label section-label--tight">Client</div>
                        <div className="drawer__text">{entry.company?.name ?? '—'}</div>
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Project</div>
                        <div className="drawer__text">{entry.project?.name ?? 'No project'}</div>
                    </div>
                </div>
            )}

            <div className={`drawer__section${showContext ? '' : ' drawer__section--divided'}`}>
                <div className="section-label section-label--tight">Team member</div>
                {canEdit && teamMembers ? (
                    <select
                        value={entry.user_id ?? ''}
                        onChange={(e) => updateField('user_id', e.target.value)}
                        className="input input--xs"
                    >
                        {!teamMembers.some((u) => u.id === entry.user_id) && entry.user && (
                            <option value={entry.user_id}>{entry.user.name} (no longer on the team)</option>
                        )}
                        {teamMembers.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                ) : (
                    <div className="drawer__text">{entry.user?.name ?? '—'}</div>
                )}
            </div>

            <div className="form-grid drawer__section">
                <div>
                    <div className="section-label section-label--tight">Date</div>
                    <input
                        type="date"
                        disabled={disabled}
                        value={entry.date.slice(0, 10)}
                        onChange={(e) => updateField('date', e.target.value)}
                        className="input input--xs"
                    />
                </div>
                <div>
                    <div className="section-label section-label--tight">Hours</div>
                    <input
                        type="number"
                        min="0.25"
                        step="0.25"
                        disabled={disabled}
                        value={hours}
                        onChange={(e) => setHours(e.target.value)}
                        onBlur={() => Number(hours) !== Number(entry.hours) && updateField('hours', hours)}
                        className="input input--xs u-tabular-nums"
                    />
                </div>
            </div>

            <ServiceField
                services={services}
                value={entry.service_id ?? ''}
                disabled={disabled || entry.billed}
                onChange={(value) => updateField('service_id', value || null)}
            />

            <div className="drawer__section">
                <div className="section-label section-label--tight">Task</div>
                <select
                    disabled={disabled}
                    value={entry.task_id ?? ''}
                    onChange={(e) => updateField('task_id', e.target.value || null)}
                    className="input input--xs"
                >
                    <option value="">No task</option>
                    {tasks.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                </select>
            </div>

            <div className="drawer__section">
                <div className="section-label">Note</div>
                <AutoResizeTextarea
                    disabled={disabled}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    onBlur={() => note !== (entry.note ?? '') && updateField('note', note)}
                    placeholder="Add a note…"
                    className="input"
                />
            </div>

        </Drawer>
    );
}

// The service a time entry is for, with a line saying what that means for
// billing. Services are set up (and marked billable or not) on the
// Services page.
function ServiceField({ services, value, disabled = false, required = false, onChange }) {
    const service = services.find((s) => String(s.id) === String(value));

    return (
        <div className="drawer__section">
            <div className="section-label section-label--tight">Service</div>
            <select
                required={required}
                disabled={disabled}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="input input--xs"
            >
                <option value="">{services.length ? 'Choose a service…' : 'No services set up yet'}</option>
                {services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
            {service && (
                <div className="form-hint form-hint--attached">
                    {service.billable ? 'Billable time.' : 'Non-billable time.'}
                </div>
            )}
        </div>
    );
}

// Log time against a project. Unlike a note or task, an entry can't exist
// half-filled (the API requires a date and hours), so this saves on
// submit. `companies` and `projects` (each with its tasks) are what can be
// picked; a project's own tab passes just itself as `fixedProjectId`,
// which hides both pickers. `teamMembers` (the project's team, for a
// manager) adds a picker for whose time it is, defaulting to `currentUserId`;
// without it the time is the signed-in person's own.
export function NewTimeEntryDrawer({ companies, projects, services = [], fixedProjectId = null, teamMembers = null, currentUserId = null, onCreated, onClose }) {
    const fixed = projects.find((p) => p.id === fixedProjectId) || null;
    const [form, setForm] = useState({
        company_id: fixed ? String(fixed.company_id) : '',
        project_id: fixed ? String(fixed.id) : '',
        task_id: '',
        service_id: '',
        user_id: currentUserId ? String(currentUserId) : '',
        date: todayLocal(),
        hours: '',
        note: '',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const projectsForCompany = projects.filter((p) => String(p.company_id) === form.company_id);
    const project = projects.find((p) => String(p.id) === form.project_id) || null;

    async function logTime(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            await api.post('/api/time-entries', {
                project_id: form.project_id,
                task_id: form.task_id || null,
                service_id: form.service_id || null,
                user_id: form.user_id || null,
                date: form.date,
                hours: form.hours,
                note: form.note,
            });
            onCreated();
            onClose();
        } catch (err) {
            setError(err.message || 'Could not log this time.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">Log time</h2>
            <form onSubmit={logTime}>
                {!fixed && (
                    <div className="form-grid drawer__section drawer__section--divided">
                        <div>
                            <div className="section-label section-label--tight">Client</div>
                            <select
                                required
                                autoFocus
                                value={form.company_id}
                                onChange={(e) => setForm({ ...form, company_id: e.target.value, project_id: '', task_id: '' })}
                                className="input input--xs"
                            >
                                <option value="">Select client</option>
                                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <div className="section-label section-label--tight">Project</div>
                            <select
                                required
                                disabled={!form.company_id}
                                value={form.project_id}
                                onChange={(e) => setForm({ ...form, project_id: e.target.value, task_id: '' })}
                                className="input input--xs"
                            >
                                <option value="">Select project</option>
                                {projectsForCompany.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </div>
                    </div>
                )}

                {teamMembers && (
                    <div className={`drawer__section${fixed ? ' drawer__section--divided' : ''}`}>
                        <div className="section-label section-label--tight">Team member</div>
                        <select
                            required
                            value={form.user_id}
                            onChange={(e) => setForm({ ...form, user_id: e.target.value })}
                            className="input input--xs"
                        >
                            {teamMembers.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                    </div>
                )}

                <div className={`form-grid drawer__section${fixed && !teamMembers ? ' drawer__section--divided' : ''}`}>
                    <div>
                        <div className="section-label section-label--tight">Date</div>
                        <input
                            required
                            type="date"
                            value={form.date}
                            onChange={(e) => setForm({ ...form, date: e.target.value })}
                            className="input input--xs"
                        />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Hours</div>
                        <input
                            required
                            autoFocus={!!fixed}
                            type="number"
                            min="0.25"
                            step="0.25"
                            value={form.hours}
                            onChange={(e) => setForm({ ...form, hours: e.target.value })}
                            className="input input--xs u-tabular-nums"
                        />
                    </div>
                </div>

                <ServiceField
                    services={services}
                    value={form.service_id}
                    required={services.length > 0}
                    onChange={(service_id) => setForm({ ...form, service_id })}
                />

                {project && (
                    <div className="drawer__section">
                        <div className="section-label section-label--tight">Task</div>
                        <select
                            value={form.task_id}
                            onChange={(e) => setForm({ ...form, task_id: e.target.value })}
                            className="input input--xs"
                        >
                            <option value="">No task</option>
                            {(project.tasks || []).map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                        </select>
                    </div>
                )}

                <div className="drawer__section">
                    <div className="section-label">Note</div>
                    <AutoResizeTextarea
                        value={form.note}
                        onChange={(e) => setForm({ ...form, note: e.target.value })}
                        placeholder="What did you work on?"
                        className="input"
                    />
                </div>

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Log time</Button>
                </div>
            </form>
        </Drawer>
    );
}
