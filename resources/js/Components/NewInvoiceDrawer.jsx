import { useState } from 'react';
import Button from './Button';
import InvoiceLineItems from './InvoiceLineItems';
import Drawer from './Drawer';
import InvoiceDateFields from './InvoiceDateFields';
import Toggle from './Toggle';
import { useSendAfterCreate } from './SendInvoiceModal';
import { api } from '../lib/api';
import { formatCurrency, invoiceSubtotal, invoiceTotal } from '../lib/format';
import { calculateDueDate, todayLocal } from '../lib/paymentTerms';
import { toPlainText } from '../lib/richText';

// Copies a proposal's line items as invoice items, scaled so they add up
// to exactly `targetCents` (the share of the budget this invoice bills).
// Proposal details are rich text; invoice details are plain, so the
// formatting is dropped.
function proposalToInvoiceItems(proposal, targetCents) {
    const items = proposal.items.map((item) => {
        const details = toPlainText(item.details);
        return {
            description: item.description,
            details: details && details !== item.description ? details : '',
            amount: parseFloat(item.quantity) * parseFloat(item.rate),
            service_id: item.service_id ? String(item.service_id) : '',
        };
    });
    const cents = splitCents(Math.max(targetCents, 0), items.map((i) => i.amount));
    return items.map((item, i) => ({ ...item, amount: (cents[i] / 100).toFixed(2) }));
}

// Splits `totalCents` across items in proportion to `weights`, in whole
// cents that add up to exactly `totalCents`. Rounding each share on its own
// can leave the sum a cent or two out (a scaled invoice landing $0.01 short
// of the remaining budget), so each share is rounded down and the leftover
// cents go to the shares that lost the most to rounding.
function splitCents(totalCents, weights) {
    const weightTotal = weights.reduce((s, w) => s + w, 0);
    if (weightTotal <= 0) return weights.map(() => 0);
    const exact = weights.map((w) => (totalCents * w) / weightTotal);
    const cents = exact.map(Math.floor);
    let leftover = totalCents - cents.reduce((s, c) => s + c, 0);
    const byRemainder = exact
        .map((value, i) => ({ i, remainder: value - Math.floor(value) }))
        .sort((a, b) => b.remainder - a.remainder);
    for (const { i } of byRemainder) {
        if (leftover <= 0) break;
        cents[i] += 1;
        leftover -= 1;
    }
    return cents;
}

function emptyInvoiceForm(defaultTerms) {
    const issuedOn = todayLocal();
    const terms = defaultTerms || 'net_30';
    return {
        proposal_id: '', items: [{ description: '', amount: '' }], surcharge: true,
        issued_on: issuedOn, payment_terms: terms, due_on: calculateDueDate(issuedOn, terms),
    };
}

const toCents = (dollars) => Math.round(dollars * 100);
const round2 = (n) => Math.round(n * 100) / 100;

// What's been invoiced on a project and what's left of its budget, in
// cents. The percentage field bills a share of `baseCents`: the project's
// budget (its accepted proposals), or the chosen proposal's own total when
// the project has no budget yet.
function billingPlan(project, proposal) {
    const invoicedCents = project
        ? project.invoices.reduce((s, inv) => s + toCents(invoiceTotal(inv.items, inv.surcharge)), 0)
        : 0;
    const budgetCents = project ? toCents(parseFloat(project.budget) || 0) : 0;
    const proposalCents = proposal
        ? toCents(proposal.items.reduce((s, i) => s + parseFloat(i.quantity) * parseFloat(i.rate), 0))
        : 0;
    const baseCents = budgetCents > 0 ? budgetCents : proposalCents;
    const remainingCents = Math.max(baseCents - invoicedCents, 0);
    return {
        baseCents,
        // Whether the percentage is of the project's budget (true) or, with
        // no budget yet, the proposal's own total.
        fromBudget: budgetCents > 0,
        invoicedCents,
        remainingCents,
        // Starts at whatever's left, so leaving it alone bills the rest.
        remainingPercent: baseCents > 0 ? round2((remainingCents / baseCents) * 100) : 0,
    };
}

// The cents a percentage bills: exactly what's left when it's the rest
// (so the default never leaves a stray cent), otherwise that share of the
// base to the cent -- never more than what's left.
function centsForPercent(plan, percent) {
    const value = parseFloat(percent);
    if (!(value > 0)) return 0;
    if (value >= plan.remainingPercent) return plan.remainingCents;
    return Math.min(Math.round((plan.baseCents * value) / 100), plan.remainingCents);
}

function proposalsWithItemsFor(project) {
    return project ? project.proposals.filter((p) => p.items.length > 0) : [];
}

// The proposal, percentage and line items a project's new invoice starts
// from: the accepted proposal billing the rest of the budget, when there's
// exactly one to choose from -- otherwise blank, to pick or type.
function seedFromProject(project) {
    const accepted = proposalsWithItemsFor(project).filter((p) => p.status === 'accepted');
    if (accepted.length !== 1) return { proposal_id: '', percent: '', items: [{ description: '', amount: '' }] };
    return seedFromProposal(project, accepted[0]);
}

function seedFromProposal(project, proposal) {
    const plan = billingPlan(project, proposal);
    const percent = String(plan.remainingPercent);
    return { proposal_id: String(proposal.id), percent, items: proposalToInvoiceItems(proposal, centsForPercent(plan, percent)) };
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

    const [form, setForm] = useState(() => ({ ...emptyInvoiceForm(defaultTerms), ...seedFromProject(project) }));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function changeProject(value) {
        setProjectId(value);
        const next = projects.find((p) => String(p.id) === value) || null;
        setForm({ ...form, ...seedFromProject(next) });
    }

    const selectedProposal = proposalsWithItems.find((p) => String(p.id) === form.proposal_id);
    const plan = selectedProposal ? billingPlan(project, selectedProposal) : null;
    const thisCents = plan ? centsForPercent(plan, form.percent) : 0;
    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge);

    function copyFromProposal(proposalId) {
        if (!proposalId) {
            setForm({ ...form, proposal_id: '', percent: '', items: [{ description: '', amount: '' }] });
            return;
        }
        const proposal = proposalsWithItems.find((p) => String(p.id) === proposalId);
        setForm({ ...form, ...seedFromProposal(project, proposal) });
    }

    // A new percentage rebuilds the line items to bill that share.
    function setPercent(percent) {
        setForm({ ...form, percent, items: proposalToInvoiceItems(selectedProposal, centsForPercent(plan, percent)) });
    }

    const pct = (cents) => (plan.baseCents > 0 ? `${round2((cents / plan.baseCents) * 100)}%` : '0%');
    // A share of the base as a bar width.
    const share = (cents) => `${plan.baseCents > 0 ? Math.min((cents / plan.baseCents) * 100, 100) : 0}%`;

    // Quick picks for the amount: common shares that still fit, then the
    // rest of the budget. The chosen one is red, like the send options.
    const percentPicks = plan
        ? [
            ...[25, 50].filter((value) => value < plan.remainingPercent).map((value) => ({ label: `${value}%`, value: String(value) })),
            { label: 'The rest', value: String(plan.remainingPercent) },
        ]
        : [];
    const isPickActive = (value) => parseFloat(form.percent) === parseFloat(value);


    // Send invoice: the dialog opens over this drawer once the invoice is
    // created; either way it ends, the drawer closes and the list refreshes.
    const { openFor: openSendDialog, modal: sendDialog } = useSendAfterCreate(() => {
        onCreated();
        onClose();
    }, onCreated);

    // `send` opens the Send Invoice dialog after creating it; otherwise
    // it's saved as a draft.
    async function createInvoice(e, { send = false } = {}) {
        e?.preventDefault();
        const validItems = form.items.filter((i) => i.description.trim() && parseFloat(i.amount) > 0);
        if (validItems.length === 0) {
            setError(
                plan && plan.remainingCents <= 0
                    ? "This project's budget is already fully invoiced, so there's nothing left to bill from the proposal. Increase the budget or enter amounts manually below."
                    : 'Add at least one line item with a description and amount.'
            );
            return;
        }
        setSaving(true);
        setError('');
        try {
            const created = await api.post(`/api/companies/${company.id}/invoices`, {
                project_id: project?.id ?? null,
                surcharge: form.surcharge,
                issued_on: form.issued_on,
                payment_terms: form.payment_terms,
                due_on: form.due_on,
                items: validItems,
            });
            if (send) {
                await openSendDialog(created.id);
                return;
            }
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
                    <div className="form-panel">
                        <div className="section-label section-label--ruled">Bill from proposal</div>

                        <div className="invoice-form__billing-field">
                            <label className="label" htmlFor="invoice-proposal">Proposal</label>
                            <select
                                id="invoice-proposal"
                                value={form.proposal_id}
                                onChange={(e) => copyFromProposal(e.target.value)}
                                className="input"
                            >
                                <option value="">Copy line items from a proposal…</option>
                                {proposalsWithItems.map((p) => (
                                    <option key={p.id} value={p.id}>{p.title} ({formatCurrency(p.estimate_amount)})</option>
                                ))}
                            </select>
                        </div>

                        {plan && (
                            plan.remainingCents <= 0 ? (
                                <div className="alert alert--danger">
                                    This project's budget is already fully invoiced — increase the budget or enter amounts manually below.
                                </div>
                            ) : (
                                <>
                                    <div className="invoice-form__billing-field">
                                        <label className="label" htmlFor="invoice-percent">Amount to bill</label>
                                        <div className="invoice-form__amount-row">
                                            <input
                                                id="invoice-percent"
                                                type="number"
                                                min="0.01"
                                                max={plan.remainingPercent}
                                                step="0.01"
                                                value={form.percent}
                                                onChange={(e) => setPercent(e.target.value)}
                                                className="input invoice-form__percent-input u-tabular-nums"
                                            />
                                            <span className="invoice-form__percent-unit">
                                                % of {formatCurrency(plan.baseCents / 100)} {plan.fromBudget ? 'budget' : 'proposal total'}
                                            </span>
                                            <div className="invoice-form__picks">
                                                {percentPicks.map((pick) => (
                                                    <button
                                                        key={pick.label}
                                                        type="button"
                                                        onClick={() => setPercent(pick.value)}
                                                        className={`btn btn--sm${isPickActive(pick.value) ? ' btn--confirm' : ' btn--secondary'}`}
                                                    >
                                                        {pick.label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>

                                    {/* The budget at a glance: invoiced, this invoice, what's left. */}
                                    <div className="invoice-form__meter" aria-hidden="true">
                                        <span className="invoice-form__meter-invoiced" style={{ width: share(plan.invoicedCents) }} />
                                        <span className="invoice-form__meter-this" style={{ width: share(thisCents) }} />
                                    </div>
                                    <div className="invoice-form__figures">
                                        <div className="invoice-form__figure">
                                            <div className="invoice-form__figure-label">Invoiced so far</div>
                                            <div className="invoice-form__figure-value">{formatCurrency(plan.invoicedCents / 100)}</div>
                                            <div className="invoice-form__figure-pct">{pct(plan.invoicedCents)}</div>
                                        </div>
                                        <div className="invoice-form__figure invoice-form__figure--this">
                                            <div className="invoice-form__figure-label">This invoice</div>
                                            <div className="invoice-form__figure-value">{formatCurrency(thisCents / 100)}</div>
                                            <div className="invoice-form__figure-pct">{pct(thisCents)}</div>
                                        </div>
                                        <div className="invoice-form__figure">
                                            <div className="invoice-form__figure-label">Left after this</div>
                                            <div className="invoice-form__figure-value">{formatCurrency((plan.remainingCents - thisCents) / 100)}</div>
                                            <div className="invoice-form__figure-pct">{pct(plan.remainingCents - thisCents)}</div>
                                        </div>
                                    </div>
                                </>
                            )
                        )}
                    </div>
                )}

                <div className="form-panel">
                    <div className="section-label section-label--ruled">Dates &amp; terms</div>
                    <InvoiceDateFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} />
                </div>

                <InvoiceLineItems items={form.items} onChange={(items) => setForm((current) => ({ ...current, items }))} />

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

                {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                {/* The card-fee toggle on the left, the form's buttons on the right. */}
                <div className="invoice-form__footer">
                    <Toggle
                        checked={form.surcharge}
                        onChange={(value) => setForm({ ...form, surcharge: value })}
                        label="Offer to pay by card (adds a 3% fee, shown only at checkout)"
                    />
                    <div className="form-actions">
                        <Button type="submit" variant="secondary" disabled={saving}>Save as draft</Button>
                        <Button type="button" variant="confirm" disabled={saving} onClick={() => createInvoice(null, { send: true })}>Send invoice</Button>
                    </div>
                </div>
            </form>
            {sendDialog}
        </Drawer>
    );
}
