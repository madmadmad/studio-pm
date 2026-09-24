import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import { formatCurrency } from '../../lib/format';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

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
            <PageHeader
                title="Services"
                actions={<Button onClick={startCreate}>Add service</Button>}
                subtitle="Your rate catalog &mdash; used as defaults when building invoice line items."
            />

            {showForm && (
                <form onSubmit={submit} className="card card-padded mb-6 grid grid-cols-2 gap-3">
                    <input required placeholder="Service name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="field col-span-2" />
                    <input required type="number" min="0" step="0.01" placeholder="Default rate ($)" value={form.default_rate} onChange={(e) => setForm({ ...form, default_rate: e.target.value })} className="field tabular-nums" />
                    <select value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} className="field">
                        <option value="hourly">Hourly</option>
                        <option value="fixed">Fixed</option>
                    </select>
                    <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="field col-span-2" />
                    <div className="flex gap-2 col-span-2 justify-end">
                        <Button type="button" variant="secondary" onClick={cancel}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Save</Button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden">
                {services.length === 0 ? (
                    <EmptyState text="No services yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Rate</th>
                                <th>Unit</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {services.map((service) => (
                                <tr key={service.id}>
                                    <td className="font-medium">{service.name}</td>
                                    <td className="tabular-nums">{formatCurrency(service.default_rate)}</td>
                                    <td className="text-shadow-grey capitalize">{service.unit}</td>
                                    <td className="text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <button onClick={() => startEdit(service)} title="Edit" className="icon-btn icon-btn-confirm">
                                                <PencilSimple />
                                            </button>
                                            <button
                                                onClick={() => remove(service)}
                                                disabled={deletingId === service.id}
                                                title="Delete"
                                                className="icon-btn icon-btn-danger"
                                            >
                                                <Trash />
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
