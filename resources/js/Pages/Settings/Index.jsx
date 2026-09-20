import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { api } from '../../lib/api';

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
            <h1 className="font-display text-2xl font-semibold mb-1">Settings</h1>
            <p className="text-sm text-shadow-grey mb-6">This is how your studio appears on proposals sent to clients.</p>

            <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 max-w-lg">
                <div className="text-xs font-semibold text-shadow-grey mb-2">Studio information</div>
                <input
                    required
                    placeholder="Studio name"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                <textarea
                    placeholder="Address"
                    value={form.address}
                    onChange={(e) => setForm({ ...form, address: e.target.value })}
                    rows={3}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                <input
                    type="email"
                    placeholder="Email"
                    value={form.email}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                <input
                    placeholder="Phone"
                    value={form.phone}
                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                <input
                    placeholder="Website"
                    value={form.website}
                    onChange={(e) => setForm({ ...form, website: e.target.value })}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-4"
                />

                <div className="text-xs font-semibold text-shadow-grey mb-2">Payment instructions</div>
                <p className="text-xs text-shadow-grey mb-2">
                    Shown on sent invoices alongside the Pay Now button, for clients who'd rather pay by ACH or check.
                </p>
                <textarea
                    placeholder={'e.g. To pay by ACH or check, contact us at hello@studio.com for our routing and account details, or mail a check to the address above.'}
                    value={form.payment_instructions}
                    onChange={(e) => setForm({ ...form, payment_instructions: e.target.value })}
                    rows={3}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-3"
                />
                <div className="flex items-center justify-end gap-3">
                    {saved && <span className="text-sm text-fern">Saved</span>}
                    <button type="submit" disabled={saving} className="bg-fern text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">
                        Save
                    </button>
                </div>
            </form>
        </AppLayout>
    );
}
