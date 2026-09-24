import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

export default function SettingsIndex({ studioProfile }) {
    const [form, setForm] = useState({
        name: studioProfile.name ?? '',
        address: studioProfile.address ?? '',
        email: studioProfile.email ?? '',
        phone: studioProfile.phone ?? '',
        website: studioProfile.website ?? '',
        payment_instructions: studioProfile.payment_instructions ?? '',
    });
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setSaved(false);
        try {
            await api.patch('/api/studio-profile', form);
            setSaved(true);
            setTimeout(() => setSaved(false), 1500);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title="Settings" />
            <PageHeader
                title="Settings"
                subtitle="This is how your studio appears on proposals sent to clients."
            />

            <form onSubmit={submit} className="card card--padded card--narrow form-stack">
                <div className="section-label">Studio information</div>
                <input
                    required
                    placeholder="Studio name"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    className="input"
                />
                <textarea
                    placeholder="Address"
                    value={form.address}
                    onChange={(e) => setForm({ ...form, address: e.target.value })}
                    rows={3}
                    className="input"
                />
                <input
                    type="email"
                    placeholder="Email"
                    value={form.email}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                    className="input"
                />
                <input
                    placeholder="Phone"
                    value={form.phone}
                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                    className="input"
                />
                <input
                    placeholder="Website"
                    value={form.website}
                    onChange={(e) => setForm({ ...form, website: e.target.value })}
                    className="input"
                />

                <div className="section-label form-stack__break">Payment instructions</div>
                <p className="form-hint">
                    Shown on sent invoices alongside the Pay Now button, for clients who'd rather pay by ACH or check.
                </p>
                <textarea
                    placeholder={'e.g. To pay by ACH or check, contact us at hello@studio.com for our routing and account details, or mail a check to the address above.'}
                    value={form.payment_instructions}
                    onChange={(e) => setForm({ ...form, payment_instructions: e.target.value })}
                    rows={3}
                    className="input"
                />
                <div className="form-actions form-stack__footer">
                    {saved && <span className="form-message form-message--success">Saved</span>}
                    <Button type="submit" variant="confirm" disabled={saving}>
                        Save
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
