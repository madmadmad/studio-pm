// Mirrors config/invoicing.php's reminder_offsets/reminder_repeat_interval_days/
// max_overdue_reminders on the PHP side (App\Services\InvoiceReminderRules)
// -- these rarely change, so this stays a plain mirror rather than a prop
// threaded through every invoice page load.
const OFFSETS = [-3, 0, 7, 14];
const REPEAT_INTERVAL_DAYS = 14;
const MAX_OVERDUE_REMINDERS = 6;

function schedule() {
    const initialOverdueCount = OFFSETS.filter((o) => o > 0).length;
    const last = Math.max(...OFFSETS);
    const days = [...OFFSETS];

    for (let i = 1; initialOverdueCount + i <= MAX_OVERDUE_REMINDERS; i++) {
        days.push(last + REPEAT_INTERVAL_DAYS * i);
    }

    return days;
}

function ruleName(offset) {
    if (offset < 0) return `due_minus_${Math.abs(offset)}`;
    if (offset === 0) return 'due_0';
    return `due_plus_${offset}`;
}

function ruleLabel(offset) {
    if (offset < 0) return `${Math.abs(offset)} days before due`;
    if (offset === 0) return 'Due today';
    return `${offset} days overdue`;
}

// One row per configured rule: its name, label, the calendar date it lands
// on for this invoice, and whether it has already gone out (or been
// skipped) according to invoice.invoice_sends.
export function reminderRows(invoice) {
    if (!invoice.due_on) return [];

    const dueDate = new Date(invoice.due_on.slice(0, 10));
    const sends = invoice.invoice_sends || [];

    return schedule().map((offset) => {
        const rule = ruleName(offset);
        const date = new Date(dueDate);
        date.setDate(date.getDate() + offset);
        const send = sends.find((s) => s.type === 'reminder' && s.reminder_rule === rule);

        return {
            rule,
            label: ruleLabel(offset),
            date,
            status: send ? send.status : 'upcoming',
        };
    });
}
