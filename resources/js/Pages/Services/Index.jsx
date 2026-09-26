import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import Button from '../../Components/Button';
import Drawer from '../../Components/Drawer';
import EmptyState from '../../Components/EmptyState';
import Toggle from '../../Components/Toggle';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import PageHeader from '../../Components/PageHeader';
import RowActions from '../../Components/RowActions';
import { formatCurrency } from '../../lib/format';
import { api } from '../../lib/api';

function formFor(service) {
    return service
        ? {
            name: service.name,
            description: service.description ?? '',
            default_rate: service.default_rate,
            unit: service.unit,
            billable: service.billable,
        }
        : { name: '', description: '', default_rate: '', unit: 'hourly', billable: true };
}

// Add (no `service`) or edit a service, in the drawer like the app's other
// records. Saves on the button; delete sits in the drawer's corner.
function ServiceDrawer({ service, onSaved, onDelete, onClose }) {
    const [form, setForm] = useState(() => formFor(service));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function field(name) {
        return { value: form[name], onChange: (e) => setForm({ ...form, [name]: e.target.value }) };
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            const saved = service
                ? await api.patch(`/api/services/${service.id}`, form)
                : await api.post('/api/services', form);
            onSaved(saved);
            onClose();
        } catch (err) {
            setError(err.message || 'Could not save this service.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer
            onClose={onClose}
            actions={service && (
                <button onClick={() => onDelete(service)} title="Delete service" className="icon-btn icon-btn--danger drawer__action">
                    <Trash />
                </button>
            )}
        >
            <h2 className="drawer__title">{service ? 'Edit service' : 'New service'}</h2>
            <form onSubmit={save}>
                <div className="drawer__section drawer__section--divided">
                    <div className="section-label section-label--tight">Name</div>
                    <input required autoFocus placeholder="e.g. Design" {...field('name')} className="input input--xs" />
                </div>
                <div className="form-grid drawer__section">
                    <div>
                        <div className="section-label section-label--tight">Default rate ($)</div>
                        <input required type="number" min="0" step="0.01" {...field('default_rate')} className="input input--xs u-tabular-nums" />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Unit</div>
                        <select {...field('unit')} className="input input--xs">
                            <option value="hourly">Hourly</option>
                            <option value="fixed">Fixed</option>
                        </select>
                    </div>
                </div>
                <div className="drawer__section">
                    <div className="section-label">Description</div>
                    <AutoResizeTextarea {...field('description')} placeholder="What this service covers…" className="input" />
                    <div className="form-hint form-hint--attached">Filled in as a line item's details when the service is picked on a proposal.</div>
                </div>
                <div className="drawer__section">
                    <Toggle checked={form.billable} onChange={(billable) => setForm({ ...form, billable })} label="Billable" />
                    <div className="form-hint form-hint--attached">
                        {form.billable
                            ? 'Time logged against this service is billable.'
                            : 'Time logged against this service is non-billable (internal work, admin).'}
                    </div>
                </div>

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>{service ? 'Save changes' : 'Add service'}</Button>
                </div>
            </form>
        </Drawer>
    );
}

export default function ServicesIndex({ services: servicesProp }) {
    const [services, setServices] = useState(servicesProp);
    // 'new' while adding, a service while editing, null when closed.
    const [editing, setEditing] = useState(null);

    useEffect(() => {
        setServices(servicesProp);
    }, [servicesProp]);

    function saved(service) {
        setServices((current) => {
            const others = current.filter((s) => s.id !== service.id);
            return [...others, service].sort((a, b) => a.name.localeCompare(b.name));
        });
    }

    function confirmMessage(service) {
        return `Delete the "${service.name}" service? This can't be undone.`;
    }

    async function destroy(service) {
        await api.delete(`/api/services/${service.id}`);
        setServices((current) => current.filter((s) => s.id !== service.id));
        setEditing(null);
    }

    // From the drawer's corner (the row's delete asks via RowActions).
    async function removeFromDrawer(service) {
        if (!confirm(confirmMessage(service))) return;
        try {
            await destroy(service);
        } catch (err) {
            alert(err.message || 'Could not delete this service.');
        }
    }

    // The row opens the service; its delete button doesn't, but the open
    // caret (.row-action) exists to, so its click is let through.
    function openRow(e, service) {
        if (e.target.closest('button:not(.row-action)')) return;
        setEditing(service);
    }

    return (
        <AppLayout>
            <Head title="Services" />
            <PageHeader
                title="Services"
                actions={<Button onClick={() => setEditing('new')}>Add service</Button>}
                subtitle="Your rate catalog &mdash; used as defaults when building invoice line items, and to mark logged time billable or not."
            />

            <div className="card card--flush">
                {services.length === 0 ? (
                    <EmptyState text="No services yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Rate</th>
                                <th>Unit</th>
                                <th>Time</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {services.map((service) => (
                                <tr key={service.id} onClick={(e) => openRow(e, service)} className="table__row--link">
                                    <td className="table__cell--strong">{service.name}</td>
                                    <td className="table__cell--numeric">{formatCurrency(service.default_rate)}</td>
                                    <td className="table__cell--muted table__cell--capitalize">{service.unit}</td>
                                    <td>
                                        {service.billable
                                            ? <Badge tone="success" label="Billable" />
                                            : <Badge tone="neutral" label="Non-billable" />}
                                    </td>
                                    <td className="table__cell--end">
                                        <RowActions
                                            openLabel={`Open ${service.name}`}
                                            deleteLabel={`Delete ${service.name}`}
                                            confirmMessage={confirmMessage(service)}
                                            onDelete={() => destroy(service)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {editing && (
                <ServiceDrawer
                    key={editing === 'new' ? 'new' : editing.id}
                    service={editing === 'new' ? null : editing}
                    onSaved={saved}
                    onDelete={removeFromDrawer}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}
