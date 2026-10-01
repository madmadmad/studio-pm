import { useEffect, useRef, useState } from 'react';
import { DotsSixVertical, Receipt } from '@phosphor-icons/react';
import Button from './Button';
import AutoResizeTextarea from './AutoResizeTextarea';
import Toggle from './Toggle';
import CurrencyInput from './CurrencyInput';
import { api } from '../lib/api';
import { formatCurrency, formatDate } from '../lib/format';

// What an expense bills: its cost plus markup (Expense::billableAmount).
function billableAmount(expense) {
    const amount = parseFloat(expense.amount) * (1 + (parseFloat(expense.markup_percent) || 0) / 100);
    return (Math.round(amount * 100) / 100).toFixed(2);
}

const isBlank = (item) => !item.id && !item.description.trim() && !(parseFloat(item.amount) > 0);

// "+ Add expense": the project's unbilled expenses, any of which can be
// added as a line. Picking one adds it (in place of a blank first line);
// the server bills the expense to the line when the invoice is saved.
function AddExpense({ projectId, items, onAdd }) {
    const [expenses, setExpenses] = useState(null);
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        let cancelled = false;
        api.get(`/api/expenses?project_id=${projectId}&billing_status=unbilled`)
            .then((list) => !cancelled && setExpenses(list))
            .catch(() => !cancelled && setExpenses([]));
        return () => { cancelled = true; };
    }, [projectId]);

    useEffect(() => {
        if (!open) return;
        const onPointerDown = (e) => !ref.current?.contains(e.target) && setOpen(false);
        const onKeyDown = (e) => {
            if (e.key === 'Escape') {
                e.stopPropagation();
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
        };
    }, [open]);

    // Ones already added to this form aren't offered again.
    const added = new Set(items.map((item) => item.expense_id).filter(Boolean));
    const available = (expenses || []).filter((expense) => !added.has(expense.id));
    if (available.length === 0) return null;

    function pick(expense) {
        onAdd({ description: expense.name, details: '', amount: billableAmount(expense), expense_id: expense.id });
        setOpen(false);
    }

    return (
        <div ref={ref} className="invoice-form__add-expense">
            <Button type="button" variant="link-accent" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
                + Add expense
            </Button>
            {open && (
                <div role="menu" className="popover invoice-form__expense-menu">
                    {available.map((expense) => (
                        <button key={expense.id} type="button" role="menuitem" onClick={() => pick(expense)} className="invoice-form__expense-option">
                            <span className="invoice-form__expense-name">
                                {expense.name}
                                <span className="invoice-form__expense-meta">
                                    {formatDate(expense.date)}
                                    {parseFloat(expense.markup_percent) > 0 && ` · ${formatCurrency(expense.amount)} + ${parseFloat(expense.markup_percent)}% markup`}
                                    {!expense.is_billable && ' · Not marked billable'}
                                </span>
                            </span>
                            <span className="invoice-form__expense-amount">{formatCurrency(billableAmount(expense))}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

// An invoice's editable line items, shared by every invoice form (the
// invoice drawer and page, the new-invoice drawer, the Invoices page).
// Laid out like a proposal's lines (Components/ProposalEditor): a drag
// handle, the description and its notes on the left, the amount on the
// right. `items` is the form's array; `onChange(items)` gets the next one
// after any edit, add, remove or reorder. Items keep whatever else they
// carry (an existing line's `id`, a new one's `expense_id`), so a save
// updates them in place. With a `projectId`, the project's unbilled
// expenses can be added as lines too. With `taxed` (the invoice charges
// sales tax), each line gets a Taxable switch.
export default function InvoiceLineItems({ items, onChange, projectId = null, taxed = false }) {
    const [dragIndex, setDragIndex] = useState(null);
    // A line is only draggable while its handle is held, so text in its
    // fields can still be selected with the mouse (as on proposals).
    const [armedIndex, setArmedIndex] = useState(null);

    function update(idx, field, value) {
        onChange(items.map((item, i) => (i === idx ? { ...item, [field]: value } : item)));
    }

    function add() {
        onChange([...items, { description: '', details: '', amount: '' }]);
    }

    function addLine(line) {
        // A lone blank line is replaced rather than left above it.
        onChange(items.length === 1 && isBlank(items[0]) ? [line] : [...items, line]);
    }

    function remove(idx) {
        onChange(items.filter((_, i) => i !== idx));
    }

    function move(fromIndex, toIndex) {
        if (fromIndex === null || fromIndex === toIndex) return;
        const next = [...items];
        const [moved] = next.splice(fromIndex, 1);
        next.splice(toIndex, 0, moved);
        onChange(next);
    }

    // On a panel under its own heading, like the form's other steps.
    return (
        <div className="form-panel">
            <div className="section-label section-label--ruled">Line items</div>
            <div className="invoice-form__items">
                {items.map((item, idx) => (
                    <div
                        key={item.id ?? `new-${idx}`}
                        draggable={armedIndex === idx}
                        onDragStart={() => setDragIndex(idx)}
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={() => {
                            move(dragIndex, idx);
                            setDragIndex(null);
                        }}
                        onDragEnd={() => {
                            setDragIndex(null);
                            setArmedIndex(null);
                        }}
                        className={`invoice-form__item${dragIndex === idx ? ' invoice-form__item--dragging' : ''}`}
                    >
                        <div
                            className="invoice-form__handle"
                            title="Drag to reorder"
                            onPointerDown={() => setArmedIndex(idx)}
                            onPointerUp={() => setArmedIndex(null)}
                        >
                            <DotsSixVertical size={14} weight="bold" />
                        </div>
                        <div className="invoice-form__item-body">
                            <div className="invoice-form__item-row">
                                <input
                                    placeholder="Line item description (required)"
                                    value={item.description}
                                    onChange={(e) => update(idx, 'description', e.target.value)}
                                    className="input invoice-form__description"
                                />
                                <CurrencyInput
                                    placeholder="Amount"
                                    value={item.amount}
                                    onChange={(value) => update(idx, 'amount', value)}
                                    className="input invoice-form__amount"
                                />
                            </div>
                            <div className="invoice-form__item-notes">
                                {/* Starts at four lines and grows with the text. */}
                                <AutoResizeTextarea
                                    placeholder="Additional notes shown to the client (optional, not required)"
                                    value={item.details || ''}
                                    onChange={(e) => update(idx, 'details', e.target.value)}
                                    rows={4}
                                    className="input invoice-form__details"
                                />
                            </div>
                            {(taxed || item.expense_id || item.from_expense || items.length > 1) && (
                                <div className="invoice-form__item-foot">
                                    {taxed && (
                                        <Toggle
                                            checked={Boolean(item.taxable)}
                                            onChange={(value) => update(idx, 'taxable', value)}
                                            label="Taxable"
                                            className="toggle--sm"
                                        />
                                    )}
                                    {(item.expense_id || item.from_expense) && (
                                        <span className="invoice-form__item-source">
                                            <Receipt size={14} /> Expense
                                        </span>
                                    )}
                                    {items.length > 1 && (
                                        <button type="button" onClick={() => remove(idx)} className="invoice-form__remove">Remove</button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
                <div className="invoice-form__adders">
                    <Button type="button" variant="link-accent" onClick={add}>+ Add line item</Button>
                    {projectId && <AddExpense projectId={projectId} items={items} onAdd={addLine} />}
                </div>
            </div>
        </div>
    );
}
