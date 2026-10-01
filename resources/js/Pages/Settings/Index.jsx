import { Head, usePage } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';

// What invoices can be for beyond project work (Hosting...): added and
// removed here, saved as you go. Removing one leaves its invoices as
// project work.
function InvoiceCategories() {
    const [categories, setCategories] = useState(usePage().props.invoiceCategories || []);
    const [name, setName] = useState('');
    const [error, setError] = useState('');

    async function add(e) {
        e.preventDefault();
        if (!name.trim()) return;
        setError('');
        try {
            const created = await api.post('/api/invoice-categories', { name: name.trim() });
            setCategories((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name)));
            setName('');
        } catch (err) {
            setError(err.errors?.name?.[0] || err.message);
        }
    }

    async function remove(category) {
        if (!confirm(`Remove the "${category.name}" category? Its invoices become project work.`)) return;
        await api.delete(`/api/invoice-categories/${category.id}`);
        setCategories((current) => current.filter((c) => c.id !== category.id));
    }

    return (
        <form onSubmit={add} className="card card--padded card--narrow form-stack page-section">
            <div className="section-label">Invoice categories</div>
            <p className="form-hint">
                For invoices that aren&rsquo;t project work, like hosting. They need no project, stay off the project boards, and add up by year in Bookkeeping&rsquo;s Invoices by category report.
            </p>
            <div className="settings__categories">
                <div className="settings__category settings__category--fixed">Project work <span className="settings__category-note">every other invoice</span></div>
                {categories.map((category) => (
                    <div key={category.id} className="settings__category">
                        {category.name}
                        <button type="button" onClick={() => remove(category)} title={`Remove ${category.name}`} aria-label={`Remove ${category.name}`} className="icon-btn icon-btn--danger">
                            <X />
                        </button>
                    </div>
                ))}
            </div>
            <div className="inline-form">
                <input placeholder="New category, e.g. Maintenance" value={name} onChange={(e) => setName(e.target.value)} aria-label="New category" className="input inline-form__grow" />
                <Button type="submit" variant="secondary">Add</Button>
            </div>
            {error && <div className="form-error">{error}</div>}
        </form>
    );
}

export default function SettingsIndex({ studioProfile }) {
    const [form, setForm] = useState({
        name: studioProfile.name ?? '',
        address: studioProfile.address ?? '',
        email: studioProfile.email ?? '',
        phone: studioProfile.phone ?? '',
        website: studioProfile.website ?? '',
        payment_instructions: studioProfile.payment_instructions ?? '',
        proposal_disclaimer: studioProfile.proposal_disclaimer ?? '',
        proposal_email_message: studioProfile.proposal_email_message ?? '',
        invoice_email_message: studioProfile.invoice_email_message ?? '',
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
            <PageHeader title="Settings" />

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

                <div className="section-label form-stack__break">Proposal email</div>
                <p className="form-hint">
                    The message the Send Proposal dialog starts with; it can be edited before each send. <code>:firm_name</code>, <code>:contact_first_name</code> and <code>:proposal_title</code> are filled in for you. Leave it blank for the standard message.
                </p>
                <AutoResizeTextarea
                    placeholder="The standard message"
                    value={form.proposal_email_message}
                    onChange={(e) => setForm({ ...form, proposal_email_message: e.target.value })}
                    rows={4}
                    className="input"
                />

                <div className="section-label form-stack__break">Invoice email</div>
                <p className="form-hint">
                    The message the Send Invoice dialog starts with; it can be edited before each send. <code>:firm_name</code>, <code>:contact_first_name</code>, <code>:invoice_number</code>, <code>:amount_due</code> and <code>:due_date</code> are filled in for you. Leave it blank for the standard message.
                </p>
                <AutoResizeTextarea
                    placeholder="The standard message"
                    value={form.invoice_email_message}
                    onChange={(e) => setForm({ ...form, invoice_email_message: e.target.value })}
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

            <InvoiceCategories />
        </AppLayout>
    );
}
