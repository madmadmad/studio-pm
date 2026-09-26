// Mirrors app/Enums/PaymentTerms.php -- same values, same day counts, same
// labels. The two can't literally share code across PHP/JS, so keep them in
// sync by hand; the server always re-validates and recomputes non-Custom
// due dates on submit regardless of what this produces client-side, so a
// drift here would only ever affect the live preview, never what's saved.
export const PAYMENT_TERMS = [
    { value: 'due_on_receipt', label: 'Due on receipt', days: 0 },
    { value: 'net_15', label: 'Net 15', days: 15 },
    { value: 'net_30', label: 'Net 30', days: 30 },
    { value: 'net_45', label: 'Net 45', days: 45 },
    { value: 'net_60', label: 'Net 60', days: 60 },
    { value: 'net_90', label: 'Net 90', days: 90 },
    { value: 'custom', label: 'Custom', days: null },
];

// The terms a client can be set to -- mirrors PaymentTerms::forClients().
// A single invoice can still use any of PAYMENT_TERMS.
export const CLIENT_PAYMENT_TERMS = PAYMENT_TERMS.filter((t) => ['net_30', 'net_60', 'net_90'].includes(t.value));

// The browser's *local* date, not UTC -- Date#toISOString() would be wrong
// for anyone west of UTC in the evening (it'd show tomorrow). This is only
// ever a client-side starting point for a fresh form; the server applies
// its own app-timezone "today" if issued_on is omitted entirely.
export function todayLocal() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function paymentTermsLabel(value) {
    return PAYMENT_TERMS.find((t) => t.value === value)?.label ?? null;
}

function daysFor(terms) {
    return PAYMENT_TERMS.find((t) => t.value === terms)?.days ?? null;
}

// Both args/return are 'YYYY-MM-DD' strings (matching <input type="date">
// and how the backend's date casts serialize) -- deliberately not Date
// objects, so nothing here is sensitive to the viewer's browser timezone.
export function calculateDueDate(issuedOn, terms) {
    const days = daysFor(terms);
    if (days === null || !issuedOn) return issuedOn;

    const date = new Date(`${issuedOn}T00:00:00`);
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
}
