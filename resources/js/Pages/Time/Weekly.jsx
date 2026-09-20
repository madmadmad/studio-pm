import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function addDays(dateStr, days) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

function EditableCell({ entry, field, type = 'text' }) {
    const [value, setValue] = useState(entry[field]);
    const [saving, setSaving] = useState(false);

    async function save() {
        if (value === entry[field]) return;
        setSaving(true);
        try {
            await api.patch(`/api/time-entries/${entry.id}`, { [field]: value });
        } finally {
            setSaving(false);
        }
    }

    return (
        <input
            type={type}
            step={type === 'number' ? '0.25' : undefined}
            value={value ?? ''}
            onChange={(e) => setValue(e.target.value)}
            onBlur={save}
            disabled={saving}
            className="border border-transparent hover:border-border focus:border-border rounded px-2 py-1 text-sm w-full bg-transparent"
        />
    );
}

export default function TimeWeekly({ weekStart, weekEnd, entries, companies }) {
    const totalHours = entries.reduce((s, e) => s + parseFloat(e.hours), 0);

    function goToWeek(newStart) {
        router.visit(`/timesheets?week_start=${newStart}`);
    }

    return (
        <AppLayout>
            <Head title="Timesheets" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Timesheets</h1>
                <div className="flex items-center gap-2 text-sm">
                    <button onClick={() => goToWeek(addDays(weekStart, -7))} className="px-2 py-1.5 rounded border border-border flex items-center">
                        <CaretLeft size={14} />
                    </button>
                    <span className="text-shadow-grey">{formatDate(weekStart)} &ndash; {formatDate(weekEnd)}</span>
                    <button onClick={() => goToWeek(addDays(weekStart, 7))} className="px-2 py-1.5 rounded border border-border flex items-center">
                        <CaretRight size={14} />
                    </button>
                </div>
            </div>
            <p className="text-sm text-shadow-grey mb-6">
                <span className="tabular-nums">{totalHours}h</span> logged this week. Edits here update the same entries shown in Time Tracking.
            </p>

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {entries.length === 0 ? (
                    <EmptyState text="No time logged for this week." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-shadow-grey">
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Hours</th>
                                <th className="px-4 py-2 font-medium">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.map((entry) => (
                                <tr key={entry.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-2">{formatDate(entry.date)}</td>
                                    <td className="px-4 py-2 text-shadow-grey">{entry.company?.name ?? '—'}</td>
                                    <td className="px-2 py-1 tabular-nums w-24">
                                        <EditableCell entry={entry} field="hours" type="number" />
                                    </td>
                                    <td className="px-2 py-1">
                                        <EditableCell entry={entry} field="note" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
