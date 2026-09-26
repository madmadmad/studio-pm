import { useState } from 'react';
import Button from './Button';
import Drawer from './Drawer';
import InvoiceDateFields from './InvoiceDateFields';
import Toggle from './Toggle';
import { api } from '../lib/api';
import { formatCurrency, invoiceSubtotal, invoiceTotal } from '../lib/format';
import { calculateDueDate, todayLocal } from '../lib/paymentTerms';
import { toPlainText } from '../lib/richText';

// Copies a proposal's line items as invoice items. When less than the full
// proposal amount remains in the project's budget (some of it already
// invoiced), scales each item down proportionally so the new invoice starts
// at exactly what's left, rather than re-billing the full proposal total.
// Proposal details are rich text; invoice details are plain, so the
// formatting is dropped.
function proposalToInvoiceItems(proposal, remaining) {
    const items = proposal.items.map((item) => {
        const details = toPlainText(item.details);
        return {
            description: item.description,
            details: details && details !== item.description ? details : '',
            amount: parseFloat(item.quantity) * parseFloat(item.rate),
            service_id: item.service_id ? String(item.service_id) : '',
        };
    });
    const proposalTotal = items.reduce((s, i) => s + i.amount, 0);
    const scale = proposalTotal > 0 && remaining < proposalTotal ? Math.max(remaining, 0) / proposalTotal : 1;
    return items.map((item) => ({ ...item, amount: (item.amount * scale).toFixed(2) }));
}

function emptyInvoiceForm(defaultTerms) {
    const issuedOn = todayLocal();
    const terms = defaultTerms || 'net_30';
    return {
        proposal_id: '', items: [{ description: '', amount: '' }], surcharge: true,
        issued_on: issuedOn, payment_terms: terms, due_on: calculateDueDate(issuedOn, terms),
    };
}

function proposalsWithItemsFor(project) {
    return project ? project.proposals.filter((p) => p.items.length > 0) : [];
}

// What's left to invoice on a project: its budget minus every invoice so far.
function remainingBudget(project) {
    if (!project) return 0;
    const invoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);
    return (parseFloat(project.budget) || 0) - invoiced;
}

// The proposal and line items a project's new invoice starts from: the
// accepted proposal's items, scaled to what's left in the budget, when
// there's exactly one to choose from -- otherwise blank, to pick or type.
function seedFromProject(project) {
    const accepted = proposalsWithItemsFor(project).filter((p) => p.status === 'accepted');
    return accepted.length === 1
        ? { proposal_id: String(accepted[0].id), items: proposalToInvoiceItems(accepted[0], remainingBudget(project)) }
        : { proposal_id: '', items: [{ description: '', amount: '' }] };
}

// Create mode for an invoice, in the wide drawer: optionally seeded from a
// proposal's line items, then dates, items, totals and the card-fee toggle.
// `projects` are the client's projects to bill against, each with its
// budget, proposals.items and invoices.items. The project page passes just
// its own project and `lockProject`; a client's page passes all of them and
// lets you pick one, or none for an invoice outside any project.
export default function NewInvoiceDrawer({ company, projects, initialProjectId = null, lockProject = false, onCreated, onClose }) {
    const defaultTerms = company.effective_payment_terms;
    const [projectId, setProjectId] = useState(initialProjectId ? String(initialProjectId) : '');
    const project = projects.find((p) => String(p.id) === projectId) || null;
    const proposalsWithItems = proposalsWithItemsFor(project);
    const remaining = remainingBudget(project);

    const [form, setForm] = useState(() => ({ ...emptyInvoiceForm(defaultTerms), ...seedFromProject(project) }));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function changeProject(value) {
        setProjectId(value);
        const next = projects.find((p) => String(p.id) === value) || null;
        setForm({ ...form, ...seedFromProject(next) });
    }

    const selectedProposal = proposalsWithItems.find((p) => String(p.id) === form.proposal_id);
    const selectedProposalTotal = selectedProposal
        ? selectedProposal.items.reduce((s, i) => s + parseFloat(i.quantity) * parseFloat(i.rate), 0)
        : 0;
    const wasScaledToRemaining = selectedProposal && remaining < selectedProposalTotal;
    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge);

    function copyFromProposal(proposalId) {
        if (!proposalId) {
            setForm({ ...form, proposal_id: '', items: [{ description: '', amount: '' }] });
            return;
        }
        const proposal = proposalsWithItems.find((p) => String(p.id) === proposalId);
        setForm({ ...form, proposal_id: proposalId, items: proposalToInvoiceItems(proposal, remaining) });
    }

    function updateItem(idx, field, value) {
        const items = form.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it));
        setForm({ ...form, items });
    }
    function addItemRow() {
        setForm({ ...form, items: [...form.items, { description: '', amount: '' }] });
    }
    function removeItemRow(idx) {
        setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
    }

    async function createInvoice(e) {
        e.preventDefault();
        const validItems = form.items.filter((i) => i.description.trim() && parseFloat(i.amount) > 0);
        if (validItems.length === 0) {
            setError(
                form.proposal_id && remaining <= 0
                    ? "This project's budget is already fully invoiced, so the copied line items scaled to $0.00. Increase the budget or enter amounts manually below."
                    : 'Add at least one line item with a description and amount.'
            );
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${company.id}/invoices`, {
                project_id: project?.id ?? null,
                surcharge: form.surcharge,
                issued_on: form.issued_on,
                payment_terms: form.payment_terms,
                due_on: form.due_on,
                items: validItems,
            });
            onCreated();
            onClose();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer size="wide" onClose={onClose}>
            <h2 className="drawer__title">New invoice</h2>
            <form onSubmit={createInvoice} className="invoice-form">
                {!lockProject && (
                    <div className="invoice-form__section">
                        <select value={projectId} onChange={(e) => changeProject(e.target.value)} className="input">
                            <option value="">No project</option>
                            {projects.map((p) => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                    </div>
                )}

                {proposalsWithItems.length > 0 && (
                    <div className="invoice-form__section">
                        <select
                            value={form.proposal_id}
                            onChange={(e) => copyFromProposal(e.target.value)}
                            className="input"
                        >
                            <option value="">Copy line items from a proposal…</option>
                            {proposalsWithItems.map((p) => (
                                <option key={p.id} value={p.id}>{p.title} ({formatCurrency(p.estimate_amount)})</option>
                            ))}
                        </select>
                        {wasScaledToRemaining && (
                            remaining <= 0 ? (
                                <div className="form-error">
                                    This project's budget is already fully invoiced, so these line items scaled to $0.00 — increase the budget or edit the amounts below.
                                </div>
                            ) : (
                                <div className="form-hint form-hint--attached">
                                    Scaled to the {formatCurrency(remaining)} left in the budget.
                                </div>
                            )
                        )}
                    </div>
                )}

                <div className="invoice-form__section">
                    <InvoiceDateFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} />
                </div>

                <div className="invoice-form__items">
                    {form.items.map((item, idx) => (
                        <div key={idx} className="invoice-form__item">
                            <div className="invoice-form__item-row">
                                <input
                                    placeholder="Line item description (required)"
                                    value={item.description}
                                    onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                    className="input invoice-form__description"
                                />
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Amount"
                                    value={item.amount}
                                    onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                    className="input invoice-form__amount"
                                />
                                {form.items.length > 1 && (
                                    <Button type="button" variant="link-accent" onClick={() => removeItemRow(idx)}>Remove</Button>
                                )}
                            </div>
                            <textarea
                                placeholder="Additional notes shown to the client (optional, not required)"
                                value={item.details || ''}
                                onChange={(e) => updateItem(idx, 'details', e.target.value)}
                                rows={2}
                                className="input invoice-form__details"
                            />
                        </div>
                    ))}
                    <Button type="button" variant="link-accent" onClick={addItemRow}>+ Add line item</Button>
                </div>

                <div className="invoice-form__section totals">
                    <div className="totals__row totals__row--muted">
                        <span>Subtotal</span>
                        <span className="totals__value">{formatCurrency(formSubtotal)}</span>
                    </div>
                    <div className="totals__row totals__row--strong">
                        <span>Total</span>
                        <span className="totals__value">{formatCurrency(formTotal)}</span>
                    </div>
                </div>

                <div className="invoice-form__section">
                    <Toggle
                        checked={form.surcharge}
                        onChange={(value) => setForm({ ...form, surcharge: value })}
                        label="Offer to pay by card (adds a 3% fee, shown only at checkout)"
                    />
                </div>
                {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Create draft invoice</Button>
                </div>
            </form>
        </Drawer>
    );
}
