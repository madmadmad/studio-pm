import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { formatCurrency } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm() {
    return { name: '', description: '', default_rate: '', unit: 'hourly' };
}

export default function ServicesIndex({ services: servicesProp }) {
    const [services, setServices] = useState(servicesProp);
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [deletingId, setDeletingId] = useState(null);

    useEffect(() => {
        setServices(servicesProp);
    }, [servicesProp]);

    function startCreate() {
        setEditingId(null);
        setForm(emptyForm());
        setShowForm(true);
    }

    function startEdit(service) {
        setEditingId(service.id);
        setForm({
            name: service.name,
            description: service.description ?? '',
            default_rate: service.default_rate,
            unit: service.unit,
        });
        setShowForm(true);
    }

    function cancel() {
        setShowForm(false);
        setEditingId(null);
        setForm(emptyForm());
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        try {
            if (editingId) {
                const updated = await api.patch(`/api/services/${editingId}`, form);
                setServices((current) => current.map((s) => (s.id === editingId ? updated : s)));
            } else {
                const created = await api.post('/api/services', form);
                setServices((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name)));
            }
            cancel();
        } finally {
            setSaving(false);
        }
    }

    async function remove(service) {
        if (deletingId === service.id) return;
        if (!confirm(`Delete the "${service.name}" service? This can't be undone.`)) return;
        setDeletingId(service.id);
        try {
            await api.delete(`/api/services/${service.id}`);
            setServices((current) => current.filter((s) => s.id !== service.id));
        } catch (err) {
            alert(err.message || 'Could not delete this service.');
        } finally {
            setDeletingId(null);
        }
    }

    return (
        <AppLayout>
            <Head title="Services" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Services</h1>
                <button onClick={startCreate} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    Add service
                </button>
            </div>
            <p className="text-sm text-sage mb-6">Your rate catalog &mdash; used as defaults when building invoice line items.</p>

            {showForm && (
                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
                    <input required placeholder="Service name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
                    <input required type="number" min="0" step="0.01" placeholder="Default rate ($)" value={form.default_rate} onChange={(e) => setForm({ ...form, default_rate: e.target.value })} className="border border-border rounded px-3 py-2 text-sm tabular-nums" />
                    <select value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                        <option value="hourly">Hourly</option>
                        <option value="fixed">Fixed</option>
                    </select>
                    <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
                    <div className="flex gap-2 col-span-2 justify-end">
                        <button type="button" onClick={cancel} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save</button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {services.length === 0 ? (
                    <EmptyState text="No services yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Rate</th>
                                <th className="px-4 py-2 font-medium">Unit</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {services.map((service) => (
                                <tr key={service.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3 font-medium">{service.name}</td>
                                    <td className="px-4 py-3 tabular-nums">{formatCurrency(service.default_rate)}</td>
                                    <td className="px-4 py-3 text-sage capitalize">{service.unit}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <button onClick={() => startEdit(service)} title="Edit" className="text-pine hover:text-pine/70">
                                                <PencilSimple size={16} />
                                            </button>
                                            <button
                                                onClick={() => remove(service)}
                                                disabled={deletingId === service.id}
                                                title="Delete"
                                                className="text-sage hover:text-brick disabled:opacity-50"
                                            >
                                                <Trash size={16} />
                                            </button>
                                        </div>
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
