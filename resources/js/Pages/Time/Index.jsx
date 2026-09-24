import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import { getTray, addToTray, setTray } from '../../lib/tray';
import PageHeader from '../../Components/PageHeader';

export default function TimeIndex({ timeEntries, companies, projects }) {
    const [showForm, setShowForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({ company_id: '', project_id: '', date: '', hours: '', note: '' });
    const [tray, setTrayState] = useState(getTray());

    const unbilledHours = timeEntries.filter((e) => !e.billed).reduce((s, e) => s + parseFloat(e.hours), 0);
    const traySubtotal = tray.reduce((s, t) => s + t.amount, 0);
    const queuedIds = new Set(tray.map((t) => t.time_entry_id));

    const projectsForCompany = projects.filter((p) => String(p.company_id) === String(form.company_id));

    async function submitTimeEntry(e) {
        e.preventDefault();
        if (!form.company_id || !form.date || !form.hours) return;
        setSaving(true);
        try {
            await api.post('/api/time-entries', {
                company_id: form.company_id,
                project_id: form.project_id || null,
                date: form.date,
                hours: form.hours,
                note: form.note,
            });
            setForm({ company_id: '', project_id: '', date: '', hours: '', note: '' });
            setShowForm(false);
            router.reload({ only: ['timeEntries'] });
        } finally {
            setSaving(false);
        }
    }

    function billEntry(entry) {
        // No client-level rate to calculate from -- rates live on Services
        // now. Starts at $0 and gets filled in on the invoice form.
        const company = companies.find((c) => c.id === entry.company_id);
        const next = addToTray({
            time_entry_id: entry.id,
            company_id: entry.company_id,
            description: `${entry.company?.name ?? company?.name ?? 'Client'} — ${entry.note || 'Time'}`,
            amount: 0,
        });
        setTrayState(next);
    }

    function removeFromTray(timeEntryId) {
        const next = tray.filter((t) => t.time_entry_id !== timeEntryId);
        setTray(next);
        setTrayState(next);
    }

    function createInvoiceFromTray() {
        router.visit('/invoices?from_tray=1');
    }

    return (
        <AppLayout>
            <Head title="Time" />
            <PageHeader
                title="Time"
                actions={<Button onClick={() => setShowForm(true)}>Log time</Button>}
                subtitle={
                    <>
                        {unbilledHours}h unbilled across {companies.length} clients.
                    </>
                }
            />

            {showForm && (
                <form onSubmit={submitTimeEntry} className="card card--padded mb-6 grid grid-cols-2 gap-3">
                    <select
                        required
                        value={form.company_id}
                        onChange={(e) => setForm({ ...form, company_id: e.target.value, project_id: '' })}
                        className="input"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select
                        value={form.project_id}
                        onChange={(e) => setForm({ ...form, project_id: e.target.value })}
                        className="input"
                        disabled={!form.company_id}
                    >
                        <option value="">No project</option>
                        {projectsForCompany.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                    <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="input" />
                    <input required type="number" min="0.25" step="0.25" placeholder="Hours" value={form.hours} onChange={(e) => setForm({ ...form, hours: e.target.value })} className="input" />
                    <input placeholder="What did you work on?" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className="input col-span-2" />
                    <div className="flex gap-2 col-span-2 justify-end">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Log time</Button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden mb-4">
                {timeEntries.length === 0 ? (
                    <EmptyState text="No time logged yet. Track hours against a client to start building an invoice." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Project</th>
                                <th>Hours</th>
                                <th>Note</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {timeEntries.map((entry) => (
                                <tr key={entry.id}>
                                    <td>{formatDate(entry.date)}</td>
                                    <td>
                                        <Link href={`/clients/${entry.company_id}`} className="hover:underline">
                                            {entry.company?.name ?? '—'}
                                        </Link>
                                    </td>
                                    <td className="text-shadow-grey">{entry.project?.name ?? '—'}</td>
                                    <td className="tabular-nums">{entry.hours}h</td>
                                    <td className="text-shadow-grey">{entry.note}</td>
                                    <td className="text-right">
                                        {entry.billed ? (
                                            <Badge tone="fern" label="Billed" />
                                        ) : queuedIds.has(entry.id) ? (
                                            <button onClick={() => removeFromTray(entry.id)}>
                                                <Badge tone="watermelon" label="Queued" />
                                            </button>
                                        ) : (
                                            <Button variant="link-accent" onClick={() => billEntry(entry)}>Bill this</Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {tray.length > 0 && (
                <div className="rounded-lg p-4 flex items-center justify-between bg-watermelon-soft">
                    <div className="text-sm">
                        {tray.length} {tray.length === 1 ? 'entry' : 'entries'} ready to bill &mdash;{' '}
                        <span className="tabular-nums">{formatCurrency(traySubtotal)}</span>
                    </div>
                    <Button variant="accent" onClick={createInvoiceFromTray}>Create invoice</Button>
                </div>
            )}
        </AppLayout>
    );
}
