import { Head, usePage } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import BankFeeds from '../../Components/BankFeeds';

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
        <form onSubmit={add} className="card card--padded form-stack">
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

// One settings card: its label, its fields, and a Save for the studio
// profile.
function SettingsCard({ id, title, onSubmit, saving, saved, children }) {
    return (
        <form onSubmit={(e) => onSubmit(e, id)} className="card card--padded form-stack">
            <div className="section-label">{title}</div>
            {children}
            <div className="form-actions form-stack__footer">
                {saved === id && <span className="form-message form-message--success">Saved</span>}
                <Button type="submit" variant="confirm" disabled={saving}>
                    Save
                </Button>
            </div>
        </form>
    );
}

export default function SettingsIndex({ studioProfile, plaidItems = [], plaidConfigured = false }) {
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
    // The card whose Save was pressed, while its "Saved" shows.
    const [saved, setSaved] = useState(null);

    async function submit(e, card) {
        e.preventDefault();
        setSaving(true);
        setSaved(null);
        try {
            await api.patch('/api/studio-profile', { ...form, sales_tax_rate: form.sales_tax_rate === '' ? null : form.sales_tax_rate });
            setSaved(card);
            setTimeout(() => setSaved(null), 1500);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title="Settings" />
            <PageHeader title="Settings" />

            {/* Each card saves the studio profile (the whole form, so a save
                never loses another card's unsaved edits). */}
            <div className="settings__columns">
                <div className="settings__column">
                    <SettingsCard id="studio" title="Studio information" onSubmit={submit} saving={saving} saved={saved}>
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
                    </SettingsCard>
                    <SettingsCard id="payment" title="Payment instructions" onSubmit={submit} saving={saving} saved={saved}>
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
                    </SettingsCard>
                    <SettingsCard id="tax" title="Sales tax" onSubmit={submit} saving={saving} saved={saved}>
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
                    </SettingsCard>
                    <InvoiceCategories />
                </div>
                <div className="settings__column">
                    <SettingsCard id="proposal-email" title="Proposal email" onSubmit={submit} saving={saving} saved={saved}>
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
                    </SettingsCard>
                    <SettingsCard id="invoice-email" title="Invoice email" onSubmit={submit} saving={saving} saved={saved}>
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
                    </SettingsCard>
                    <SettingsCard id="disclaimer" title="Estimate disclaimer" onSubmit={submit} saving={saving} saved={saved}>
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
                    </SettingsCard>
                    <BankFeeds items={plaidItems} configured={plaidConfigured} />
                </div>
            </div>
        </AppLayout>
    );
}
