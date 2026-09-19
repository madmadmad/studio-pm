import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import { getTray, addToTray, setTray } from '../../lib/tray';

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
        const company = companies.find((c) => c.id === entry.company_id);
        const rate = company?.default_hourly_rate || 0;
        const amount = parseFloat(entry.hours) * parseFloat(rate);
        const next = addToTray({
            time_entry_id: entry.id,
            company_id: entry.company_id,
            description: `${entry.company?.name ?? company?.name ?? 'Client'} — ${entry.note || 'Time'}`,
            amount,
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
            <div className="flex items-center justify-between mb-1">
                <h1 className="text-2xl font-semibold">Time</h1>
                <button onClick={() => setShowForm(true)} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    Log time
                </button>
            </div>
            <p className="text-sm text-sage mb-6">{unbilledHours}h unbilled across {companies.length} clients.</p>

            {showForm && (
                <form onSubmit={submitTimeEntry} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
                    <select
                        required
                        value={form.company_id}
                        onChange={(e) => setForm({ ...form, company_id: e.target.value, project_id: '' })}
                        className="border border-border rounded px-3 py-2 text-sm"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select
                        value={form.project_id}
                        onChange={(e) => setForm({ ...form, project_id: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm"
                        disabled={!form.company_id}
                    >
                        <option value="">No project</option>
                        {projectsForCompany.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                    <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input required type="number" min="0.25" step="0.25" placeholder="Hours" value={form.hours} onChange={(e) => setForm({ ...form, hours: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input placeholder="What did you work on?" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
                    <div className="flex gap-2 col-span-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Log time</button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden mb-4">
                {timeEntries.length === 0 ? (
                    <EmptyState text="No time logged yet. Track hours against a client to start building an invoice." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Project</th>
                                <th className="px-4 py-2 font-medium">Hours</th>
                                <th className="px-4 py-2 font-medium">Note</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {timeEntries.map((entry) => (
                                <tr key={entry.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3">{formatDate(entry.date)}</td>
                                    <td className="px-4 py-3">
                                        <Link href={`/clients/${entry.company_id}`} className="hover:underline">
                                            {entry.company?.name ?? '—'}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-sage">{entry.project?.name ?? '—'}</td>
                                    <td className="px-4 py-3 font-mono">{entry.hours}h</td>
                                    <td className="px-4 py-3 text-sage">{entry.note}</td>
                                    <td className="px-4 py-3 text-right">
                                        {entry.billed ? (
                                            <Badge tone="pine" label="Billed" />
                                        ) : queuedIds.has(entry.id) ? (
                                            <button onClick={() => removeFromTray(entry.id)}>
                                                <Badge tone="brass" label="Queued" />
                                            </button>
                                        ) : (
                                            <button onClick={() => billEntry(entry)} className="text-sm font-medium text-brass">
                                                Bill this
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {tray.length > 0 && (
                <div className="rounded-lg p-4 flex items-center justify-between bg-brass-soft">
                    <div className="text-sm">
                        {tray.length} {tray.length === 1 ? 'entry' : 'entries'} ready to bill &mdash;{' '}
                        <span className="font-mono">{formatCurrency(traySubtotal)}</span>
                    </div>
                    <button onClick={createInvoiceFromTray} className="bg-brass text-white text-sm font-medium px-3 py-1.5 rounded">
                        Create invoice
                    </button>
                </div>
            )}
        </AppLayout>
    );
}
