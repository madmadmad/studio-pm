import { PAYMENT_TERMS, calculateDueDate } from '../lib/paymentTerms';

// The one place the "change issue date -> recompute unless custom / change
// terms -> recompute / hand-edit due date -> switch to Custom / change
// client -> recompute unless already customized" state machine lives, so
// the three invoice-creation forms (Invoices/Index, Projects/Show's
// BillingTab) and the edit form (Invoices/Show) can't drift from each
// other. Fully controlled -- no state of its own, it just computes the
// right patch to merge into whichever form-state object called it.
export function useInvoiceDateFields(values, onChange) {
    function setIssuedOn(value) {
        const patch = { issued_on: value };
        if (values.payment_terms !== 'custom') {
            patch.due_on = calculateDueDate(value, values.payment_terms);
        }
        onChange(patch);
    }

    function setTerms(value) {
        const patch = { payment_terms: value };
        if (value !== 'custom') {
            patch.due_on = calculateDueDate(values.issued_on, value);
        }
        onChange(patch);
    }

    function setDueOn(value) {
        onChange({ due_on: value, payment_terms: 'custom' });
    }

    // Called when the invoice's client changes -- a manually-customized due
    // date (terms already 'custom') is left alone on purpose.
    function applyClientDefaultTerms(clientTerms) {
        if (values.payment_terms === 'custom') return;
        onChange({ payment_terms: clientTerms, due_on: calculateDueDate(values.issued_on, clientTerms) });
    }

    return { setIssuedOn, setTerms, setDueOn, applyClientDefaultTerms };
}

export default function InvoiceDateFields({ values, onChange }) {
    const { setIssuedOn, setTerms, setDueOn } = useInvoiceDateFields(values, onChange);

    return (
        <div className="grid grid-cols-3 gap-3">
            <div>
                <label className="field-label">Issue date</label>
                <input type="date" value={values.issued_on} onChange={(e) => setIssuedOn(e.target.value)} className="field" />
            </div>
            <div>
                <label className="field-label">Payment terms</label>
                <select value={values.payment_terms} onChange={(e) => setTerms(e.target.value)} className="field">
                    {PAYMENT_TERMS.map((t) => (
                        <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                </select>
            </div>
            <div>
                <label className="field-label">Due date</label>
                <input type="date" value={values.due_on} onChange={(e) => setDueOn(e.target.value)} className="field" />
            </div>
        </div>
    );
}
