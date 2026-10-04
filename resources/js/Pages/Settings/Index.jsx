import { Head, router, usePage } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import BankFeeds from '../../Components/BankFeeds';
import TabBar from '../../Components/TabBar';
import { useRememberedTab } from '../../lib/useRememberedTab';

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
        <form onSubmit={add} className="card card--padded form-stack settings__card">
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
        <form onSubmit={(e) => onSubmit(e, id)} className="card card--padded form-stack settings__card">
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

// Every notification email's wording (new message, invites, password
// reset...), one at a time: pick the email, edit its subject, heading,
// message, button and note. :placeholders are listed for each; Reset
// puts back the default. Saved with the rest of the profile, keeping only
// what differs from the default.
function NotificationEmails({ definitions, templates, onChange }) {
    const keys = Object.keys(definitions);
    const [key, setKey] = useState(keys[0]);
    const definition = definitions[key];
    const fields = templates[key];
    const isDefault = Object.keys(definition.defaults).every((field) => fields[field] === definition.defaults[field]);

    function set(field, value) {
        onChange({ ...templates, [key]: { ...fields, [field]: value } });
    }

    return (
        <>
            <p className="form-hint">
                The wording of the emails the app sends on its own. Words starting with a colon are filled in when it&rsquo;s sent; blank lines split the message into paragraphs. Clear the heading or note to leave it out.
            </p>
            <div className="settings__email-pick">
                <select value={key} onChange={(e) => setKey(e.target.value)} aria-label="Email" className="input">
                    {keys.map((k) => <option key={k} value={k}>{definitions[k].label}</option>)}
                </select>
                <span className="settings__category-note">To: {definition.audience}</span>
            </div>
            <p className="form-hint">
                Fills in: {definition.placeholders.map((p, i) => <span key={p}>{i > 0 && ', '}<code>:{p}</code></span>)}
            </p>
            <label className="section-label section-label--tight form-stack__break" htmlFor="email-subject">Subject</label>
            <input id="email-subject" value={fields.subject} onChange={(e) => set('subject', e.target.value)} className="input" />
            <label className="section-label section-label--tight form-stack__break" htmlFor="email-heading">Heading</label>
            <input id="email-heading" value={fields.heading} onChange={(e) => set('heading', e.target.value)} placeholder="No heading" className="input" />
            <label className="section-label section-label--tight form-stack__break" htmlFor="email-message">Message</label>
            <AutoResizeTextarea id="email-message" value={fields.message} onChange={(e) => set('message', e.target.value)} rows={3} className="input" />
            <label className="section-label section-label--tight form-stack__break" htmlFor="email-button">Button</label>
            <input id="email-button" value={fields.button} onChange={(e) => set('button', e.target.value)} className="input" />
            <label className="section-label section-label--tight form-stack__break" htmlFor="email-note">Note below the button</label>
            <AutoResizeTextarea id="email-note" value={fields.note} onChange={(e) => set('note', e.target.value)} placeholder="No note" rows={2} className="input" />
            {!isDefault && (
                <div>
                    <button type="button" onClick={() => onChange({ ...templates, [key]: { ...definition.defaults } })} className="text-action text-action--xs">
                        Reset {definition.label.toLowerCase()} to the default
                    </button>
                </div>
            )}
        </>
    );
}

// The studio's logo, in its two versions -- each shown on the background it's
// for, with Upload (SVG or PNG) and, once one's uploaded, Use default. It
// replaces the logo everywhere: sidebar, sign-in, public invoice and
// proposal pages, emails and PDFs. Saves as soon as a file is picked.
function StudioLogo({ initial }) {
    const [logos, setLogos] = useState(initial);
    const [busy, setBusy] = useState(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    async function run(variant, task) {
        setBusy(variant);
        setError('');
        setMessage('');
        try {
            const result = await task();
            setLogos(result);
            if (result.warning) setMessage(result.warning);
            // The sidebar's logo comes from the shared props.
            router.reload({ only: ['branding'] });
        } catch (err) {
            setError(err.message || 'Could not update the logo.');
        } finally {
            setBusy(null);
        }
    }

    function upload(variant, file) {
        if (!file) return;
        const fd = new FormData();
        fd.append('logo', file);
        run(variant, () => api.postForm(`/api/studio-profile/logo/${variant}`, fd));
    }

    const versions = [
        { variant: 'light', label: 'For light backgrounds', src: logos.logo, custom: logos.custom_light },
        { variant: 'dark', label: 'For dark backgrounds', src: logos.logo_dark, custom: logos.custom_dark },
    ];

    return (
        <div className="card card--padded form-stack settings__card">
            <div className="section-label">Logo</div>
            <p className="form-hint">
                Used across the app, on sign-in screens, client links, emails and PDFs. Upload an SVG or PNG; the dark-background version is the one for dark mode.
            </p>
            <div className="settings__logos">
                {versions.map((v) => (
                    <div key={v.variant} className="settings__logo">
                        <div className={`settings__logo-preview settings__logo-preview--${v.variant}`}>
                            <img src={v.src} alt="" />
                        </div>
                        <div className="settings__logo-label">{v.label}</div>
                        <div className="settings__logo-actions">
                            <label className={`btn btn--secondary settings__upload${busy ? ' settings__upload--busy' : ''}`}>
                                {busy === v.variant ? 'Uploading…' : 'Upload'}
                                <input
                                    type="file"
                                    accept=".svg,.png,image/svg+xml,image/png"
                                    disabled={busy !== null}
                                    onChange={(e) => { upload(v.variant, e.target.files[0]); e.target.value = ''; }}
                                    hidden
                                />
                            </label>
                            {v.custom && (
                                <button type="button" disabled={busy !== null} onClick={() => run(v.variant, () => api.delete(`/api/studio-profile/logo/${v.variant}`))} className="text-action text-action--xs">
                                    Use default
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
            {message && <div className="form-hint">{message}</div>}
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

// Settings by the part of the app they shape, a tab each (linkable as
// ?tab=Expenses).
const TABS = ['General', 'Proposals', 'Invoices & payments', 'Expenses', 'Notifications'];

// Each template's wording: what was changed in Settings, else the default.
function initialTemplates(definitions, saved) {
    return Object.fromEntries(Object.entries(definitions).map(([key, definition]) => [
        key,
        Object.fromEntries(Object.entries(definition.defaults).map(([field, value]) => [field, saved?.[key]?.[field] ?? value])),
    ]));
}

export default function SettingsIndex({ studioProfile, logos, emailTemplates = {}, plaidItems = [], plaidConfigured = false }) {
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
        email_templates: initialTemplates(emailTemplates, studioProfile.email_templates),
        sales_tax_name: studioProfile.sales_tax_name ?? '',
        sales_tax_rate: studioProfile.sales_tax_rate != null ? String(parseFloat(studioProfile.sales_tax_rate)) : '',
    });
    const [tab, setTab] = useRememberedTab('settings-page-tab', TABS, { param: 'tab' });
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
            <TabBar tabs={TABS} tab={tab} setTab={setTab} size="lg" />

            {tab === 'General' && (
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
                    </div>
                    <div className="settings__column">
                        <StudioLogo initial={logos} />
                    </div>
                </div>
            )}

            {tab === 'Proposals' && (
                <div className="settings__columns">
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
                    </div>
                    <div className="settings__column">
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
                    </div>
                </div>
            )}

            {tab === 'Invoices & payments' && (
                <div className="settings__columns">
                    <div className="settings__column">
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
                    </div>
                    <div className="settings__column">
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
                </div>
            )}

            {tab === 'Expenses' && (
                <div className="settings__columns">
                    <div className="settings__column">
                        <BankFeeds items={plaidItems} configured={plaidConfigured} />
                    </div>
                </div>
            )}

            {tab === 'Notifications' && (
                    <SettingsCard id="emails" title="Notification emails" onSubmit={submit} saving={saving} saved={saved}>
                        <NotificationEmails
                            definitions={emailTemplates}
                            templates={form.email_templates}
                            onChange={(email_templates) => setForm({ ...form, email_templates })}
                        />
                    </SettingsCard>
            )}
        </AppLayout>
    );
}
