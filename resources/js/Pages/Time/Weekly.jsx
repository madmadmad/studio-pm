import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

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
            <PageHeader
                title="Timesheets"
                actions={
                    <div className="choice">
                        <button onClick={() => goToWeek(addDays(weekStart, -7))} className="px-2 py-1.5 rounded border border-border flex items-center">
                            <CaretLeft size={14} />
                        </button>
                        <span className="text-shadow-grey">{formatDate(weekStart)} &ndash; {formatDate(weekEnd)}</span>
                        <button onClick={() => goToWeek(addDays(weekStart, 7))} className="px-2 py-1.5 rounded border border-border flex items-center">
                            <CaretRight size={14} />
                        </button>
                    </div>
                }
                subtitle={
                    <>
                        <span className="tabular-nums">{totalHours}h</span> logged this week. Edits here update the same entries shown in Time Tracking.
                    </>
                }
            />

            <div className="card overflow-hidden">
                {entries.length === 0 ? (
                    <EmptyState text="No time logged for this week." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Hours</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.map((entry) => (
                                <tr key={entry.id}>
                                    <td>{formatDate(entry.date)}</td>
                                    <td className="text-shadow-grey">{entry.company?.name ?? '—'}</td>
                                    <td className="table__cell--tight tabular-nums w-24">
                                        <EditableCell entry={entry} field="hours" type="number" />
                                    </td>
                                    <td className="table__cell--tight">
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
