import { useState } from 'react';
import { DotsSixVertical } from '@phosphor-icons/react';
import Button from './Button';
import AutoResizeTextarea from './AutoResizeTextarea';

// An invoice's editable line items, shared by every invoice form (the
// invoice drawer and page, the new-invoice drawer, the Invoices page).
// Laid out like a proposal's lines (Components/ProposalEditor): a drag
// handle, the description and its notes on the left, the amount on the
// right. `items` is the form's array; `onChange(items)` gets the next one
// after any edit, add, remove or reorder. Items keep whatever else they
// carry (an existing line's `id`), so a save updates them in place.
export default function InvoiceLineItems({ items, onChange }) {
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
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Amount"
                                    value={item.amount}
                                    onChange={(e) => update(idx, 'amount', e.target.value)}
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
                            {items.length > 1 && (
                                <button type="button" onClick={() => remove(idx)} className="invoice-form__remove">Remove</button>
                            )}
                        </div>
                    </div>
                ))}
                <Button type="button" variant="link-accent" onClick={add}>+ Add line item</Button>
            </div>
        </div>
    );
}
