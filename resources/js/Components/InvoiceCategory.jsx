import { usePage } from '@inertiajs/react';
import { ArrowsClockwise } from '@phosphor-icons/react';
import Badge from './Badge';
import { formatDate } from '../lib/format';

// What an invoice is for: project work (no category), or one of the
// categories set up in Settings (Hosting...). Shared by the invoice forms,
// lists and the Invoices by category report.
export const PROJECT_WORK = 'Project work';

export function categoryName(invoice) {
    return invoice.category?.name ?? PROJECT_WORK;
}

// The categories, from the shared `invoiceCategories` prop.
export function useInvoiceCategories() {
    return usePage().props.invoiceCategories || [];
}

// A form's Category picker. `value` is the category id as a string, ''
// for project work.
export function InvoiceCategorySelect({ value, onChange, className = 'input' }) {
    const categories = useInvoiceCategories();
    if (categories.length === 0) return null;

    return (
        <select value={value} onChange={(e) => onChange(e.target.value)} aria-label="Category" className={className}>
            <option value="">{PROJECT_WORK}</option>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
    );
}

// A small label for an invoice that isn't project work, beside its name
// in a list; nothing for project work.
export function InvoiceCategoryBadge({ invoice }) {
    return invoice.category ? <Badge tone="neutral" label={invoice.category.name} /> : null;
}

// Filter pills' options for these invoices: All, then each category they
// use (project work included) -- or none at all when they're all project
// work, so a list with nothing to tell apart gets no pills.
export function categoryFilters(invoices) {
    const names = [...new Set(invoices.map(categoryName))];
    if (names.length < 2 && !names.some((n) => n !== PROJECT_WORK)) return [];
    const ordered = [PROJECT_WORK, ...names.filter((n) => n !== PROJECT_WORK).sort()].filter((n) => names.includes(n));
    return [{ value: 'all', label: 'All' }, ...ordered.map((n) => ({ value: n, label: n }))];
}

// A repeating invoice (it sends a new copy every month or year), marked in
// a list with a small icon; its tooltip says how often and when next.
export function RepeatIcon({ invoice }) {
    if (!invoice.repeat) return null;
    const every = invoice.repeat === 'yearly' ? 'every year' : 'every month';
    const label = `Repeats ${every}${invoice.next_repeat_on ? ` · next ${formatDate(invoice.next_repeat_on.slice(0, 10))}` : ''}`;
    return (
        <span className="repeat-icon" title={label} aria-label={label} role="img">
            <ArrowsClockwise weight="bold" />
        </span>
    );
}
