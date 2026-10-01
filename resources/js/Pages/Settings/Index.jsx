import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';

export default function SettingsIndex({ studioProfile }) {
    const [form, setForm] = useState({
        name: studioProfile.name ?? '',
        address: studioProfile.address ?? '',
        email: studioProfile.email ?? '',
        phone: studioProfile.phone ?? '',
        website: studioProfile.website ?? '',
        payment_instructions: studioProfile.payment_instructions ?? '',
        proposal_disclaimer: studioProfile.proposal_disclaimer ?? '',
        sales_tax_name: studioProfile.sales_tax_name ?? '',
        sales_tax_rate: studioProfile.sales_tax_rate != null ? String(parseFloat(studioProfile.sales_tax_rate)) : '',
    });
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setSaved(false);
        try {
            await api.patch('/api/studio-profile', { ...form, sales_tax_rate: form.sales_tax_rate === '' ? null : form.sales_tax_rate });
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
                subtitle="How your studio appears to clients, and the defaults for proposals and invoices."
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

                <div className="section-label form-stack__break">Estimate disclaimer</div>
                <p className="form-hint">
                    New proposals start with this below the scope of work, above the services. It can be edited on each proposal; changing it here doesn&rsquo;t change proposals already written. Leave it blank for none.
                </p>
                <AutoResizeTextarea
                    placeholder="No default disclaimer"
                    value={form.proposal_disclaimer}
                    onChange={(e) => setForm({ ...form, proposal_disclaimer: e.target.value })}
                    rows={3}
                    className="input"
                />

                <div className="section-label form-stack__break">Sales tax</div>
                <p className="form-hint">
                    Charged on an invoice&rsquo;s taxable lines when Charge Tax is on. An invoice keeps the rate it was charged at, so changing it here only affects invoices you turn tax on for afterwards. Leave the rate blank to hide Charge Tax.
                </p>
                <div className="settings__tax">
                    <input
                        placeholder="Name, e.g. Ohio sales tax"
                        value={form.sales_tax_name}
                        onChange={(e) => setForm({ ...form, sales_tax_name: e.target.value })}
                        aria-label="Sales tax name"
                        className="input"
                    />
                    <div className="settings__rate">
                        <input
                            type="number"
                            min="0"
                            max="100"
                            step="0.001"
                            placeholder="Rate"
                            value={form.sales_tax_rate}
                            onChange={(e) => setForm({ ...form, sales_tax_rate: e.target.value })}
                            aria-label="Sales tax rate (%)"
                            className="input u-tabular-nums"
                        />
                        <span className="settings__unit">%</span>
                    </div>
                </div>
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
