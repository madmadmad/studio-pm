import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CaretDown, CaretRight, DownloadSimple, Paperclip, PaperclipHorizontal, Trash, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { ExpenseStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, formatFileSize, todayInAppTimezone } from '../../lib/format';
import MetricCard from '../../Components/MetricCard';
import { useListMotion } from '../../lib/listMotion';
import { expenseCategoryIcon } from '../../lib/expenseCategoryIcon';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import Drawer, { DrawerByline, DrawerDate } from '../../Components/Drawer';
import Toggle from '../../Components/Toggle';

function emptyForm() {
    return {
        name: '',
        amount: '',
        currency: 'USD',
        category_id: '',
        project_id: '',
        is_billable: false,
        markup_percent: '0',
        tax_id: '',
        date: new Date().toISOString().slice(0, 10),
        is_recurring: false,
        recurrence_interval: 'monthly',
        source_label: '',
    };
}

function toFormData(form, receiptFile) {
    const fd = new FormData();
    Object.entries(form).forEach(([key, value]) => {
        if (key === 'is_billable' || key === 'is_recurring') {
            fd.append(key, value ? '1' : '0');
        } else if (value !== '' && value !== null && value !== undefined) {
            fd.append(key, value);
        }
    });
    if (receiptFile) fd.append('receipt', receiptFile);
    return fd;
}

function CategoryPill({ category }) {
    if (!category) return <span className="expenses__no-category">&mdash;</span>;
    const Icon = expenseCategoryIcon(category.name);
    return (
        <span className="expenses__category">
            <Icon className="expenses__category-icon" />
            {category.name}
        </span>
    );
}

// The expense categories and taxes, in a drawer opened from the filter
// bar's "Categories & taxes" button.
function CategoryAndTaxManager({ categories, setCategories, taxes, setTaxes }) {
    const [categoryForm, setCategoryForm] = useState({ name: '', color: '#595F64' });
    const [taxForm, setTaxForm] = useState({ name: '', rate: '' });

    async function addCategory(e) {
        e.preventDefault();
        if (!categoryForm.name.trim()) return;
        const created = await api.post('/api/expense-categories', categoryForm);
        setCategories((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name)));
        setCategoryForm({ name: '', color: '#595F64' });
    }

    async function removeCategory(category) {
        if (!confirm(`Delete the "${category.name}" category?`)) return;
        await api.delete(`/api/expense-categories/${category.id}`);
        setCategories((current) => current.filter((c) => c.id !== category.id));
    }

    async function addTax(e) {
        e.preventDefault();
        if (!taxForm.name.trim() || taxForm.rate === '') return;
        const created = await api.post('/api/taxes', taxForm);
        setTaxes((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name)));
        setTaxForm({ name: '', rate: '' });
    }

    async function removeTax(tax) {
        if (!confirm(`Delete the "${tax.name}" tax?`)) return;
        await api.delete(`/api/taxes/${tax.id}`);
        setTaxes((current) => current.filter((t) => t.id !== tax.id));
    }

    return (
        <div className="expenses__manager">
            <div className="form-panel">
                <h3 className="expenses__manager-title">Categories</h3>
                <div className="expenses__manager-list">
                    {categories.map((category) => (
                        <div key={category.id} className="expenses__manager-item">
                            <CategoryPill category={category} />
                            <button onClick={() => removeCategory(category)} className="icon-btn icon-btn--danger">
                                <X />
                            </button>
                        </div>
                    ))}
                </div>
                <form onSubmit={addCategory} className="inline-form">
                    <input type="color" value={categoryForm.color} onChange={(e) => setCategoryForm({ ...categoryForm, color: e.target.value })} className="expenses__swatch-input" />
                    <input placeholder="New category" value={categoryForm.name} onChange={(e) => setCategoryForm({ ...categoryForm, name: e.target.value })} className="input inline-form__grow" />
                    <Button type="submit">Add</Button>
                </form>
            </div>
            <div className="form-panel">
                <h3 className="expenses__manager-title">Taxes</h3>
                <div className="expenses__manager-list">
                    {taxes.map((tax) => (
                        <div key={tax.id} className="expenses__manager-item">
                            <span>{tax.name} <span className="expenses__rate">({tax.rate}%)</span></span>
                            <button onClick={() => removeTax(tax)} className="icon-btn icon-btn--danger">
                                <X />
                            </button>
                        </div>
                    ))}
                </div>
                <form onSubmit={addTax} className="inline-form">
                    <input placeholder="Tax name" value={taxForm.name} onChange={(e) => setTaxForm({ ...taxForm, name: e.target.value })} className="input inline-form__grow" />
                    <input type="number" min="0" max="100" step="0.01" placeholder="Rate %" value={taxForm.rate} onChange={(e) => setTaxForm({ ...taxForm, rate: e.target.value })} className="input u-tabular-nums expenses__rate-input" />
                    <Button type="submit">Add</Button>
                </form>
            </div>
        </div>
    );
}

// The expense's receipt, laid out like a task's Files: a panel with the
// paperclip to add (or replace) it, and the file as a row with its
// actions. A newly picked file is saved with the expense, so until then
// it can be taken off again; a saved one opens in a new tab.
function ReceiptPanel({ expense, file, onPick, onClear }) {
    const inputRef = useRef(null);
    const current = file
        ? { name: file.name, meta: `${formatFileSize(file.size)} · Saved with the expense`, pending: true }
        : expense?.receipt_url ? { name: expense.receipt_filename || 'Receipt', meta: 'Attached', url: expense.receipt_url } : null;

    return (
        <div className="form-panel expenses__receipt">
            <div className="form-panel__header">
                <div className="section-label section-label--flush">Receipt</div>
                <button
                    type="button"
                    onClick={() => inputRef.current.click()}
                    title={current ? 'Replace receipt' : 'Add receipt'}
                    aria-label={current ? 'Replace receipt' : 'Add receipt'}
                    className="icon-btn icon-btn--secondary"
                >
                    <Paperclip />
                </button>
                <input
                    ref={inputRef}
                    type="file"
                    accept="image/*,.pdf"
                    onChange={(e) => {
                        onPick(e.target.files?.[0] ?? null);
                        e.target.value = '';
                    }}
                    hidden
                />
            </div>
            {!current ? (
                <EmptyState text="No receipt yet." />
            ) : (
                <div className="task-files__list">
                    <div className="task-files__item">
                        <div className="task-files__info">
                            <div className="task-files__name">{current.name}</div>
                            <div className="task-files__size">{current.meta}</div>
                        </div>
                        <div className="task-files__actions">
                            {current.url && (
                                <a href={current.url} target="_blank" rel="noreferrer" title="View receipt" className="icon-btn icon-btn--secondary task-files__action">
                                    <DownloadSimple />
                                </a>
                            )}
                            {current.pending && (
                                <button type="button" onClick={onClear} title="Remove" aria-label="Remove" className="icon-btn icon-btn--danger task-files__action">
                                    <X />
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'unbilled', label: 'Unbilled' },
    { value: 'billed', label: 'Billed' },
    { value: 'billed_and_paid', label: 'Paid' },
];

// The figures across the top, as on the Invoices page: what's been spent
// this month and this year, billable costs not yet on an invoice, and
// ones invoiced but not yet paid -- each a count and an amount. Months
// are calendar ones (an expense's date is a plain date).
function expenseMetrics(expenses) {
    const today = todayInAppTimezone();
    const figure = (list) => ({ count: list.length, amount: list.reduce((s, e) => s + (parseFloat(e.amount) || 0), 0) });
    const dated = (prefix) => expenses.filter((e) => e.date?.slice(0, prefix.length) === prefix);

    return {
        month: figure(dated(today.slice(0, 7))),
        year: figure(dated(today.slice(0, 4))),
        ready: figure(expenses.filter((e) => e.is_billable && e.billing_status === 'unbilled')),
        billed: figure(expenses.filter((e) => e.billing_status === 'billed')),
    };
}

export default function ExpensesIndex({ expenses: expensesProp, categories: categoriesProp, taxes: taxesProp, projects, draftInvoices }) {
    const [expenses, setExpenses] = useState(expensesProp);
    const [categories, setCategories] = useState(categoriesProp);
    const [taxes, setTaxes] = useState(taxesProp);
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [managing, setManaging] = useState(false);
    const rowsRef = useListMotion();
    const [showForm, setShowForm] = useState(false);
    // The expense open in the drawer (null while creating).
    const [editing, setEditing] = useState(null);
    const editingId = editing?.id ?? null;
    // Billed expenses can't be changed (the API refuses) -- they open read-only.
    const editable = !editing || editing.billing_status === 'unbilled';
    const [form, setForm] = useState(emptyForm());
    const [receiptFile, setReceiptFile] = useState(null);
    const [showAdditional, setShowAdditional] = useState(false);
    const [saving, setSaving] = useState(false);
    const [attachingId, setAttachingId] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => setExpenses(expensesProp), [expensesProp]);
    useEffect(() => setCategories(categoriesProp), [categoriesProp]);
    useEffect(() => setTaxes(taxesProp), [taxesProp]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return expenses.filter((e) => {
            if (q && !e.name.toLowerCase().includes(q)) return false;
            if (categoryFilter === 'uncategorized' && e.category_id) return false;
            if (categoryFilter && categoryFilter !== 'uncategorized' && String(e.category_id) !== categoryFilter) return false;
            if (statusFilter !== 'all' && e.billing_status !== statusFilter) return false;
            return true;
        });
    }, [expenses, search, categoryFilter, statusFilter]);
    const metrics = expenseMetrics(expenses);

    function startCreate() {
        setEditing(null);
        setForm(emptyForm());
        setReceiptFile(null);
        setError('');
        setShowForm(true);
    }

    function startEdit(expense) {
        setEditing(expense);
        setForm({
            name: expense.name,
            amount: expense.amount,
            currency: expense.currency,
            category_id: expense.category_id ?? '',
            project_id: expense.project_id ?? '',
            is_billable: expense.is_billable,
            markup_percent: expense.markup_percent,
            tax_id: expense.tax_id ?? '',
            date: expense.date?.slice(0, 10) ?? '', // a date input takes YYYY-MM-DD, not the full timestamp
            is_recurring: expense.is_recurring,
            recurrence_interval: expense.recurrence_interval ?? 'monthly',
            source_label: expense.source_label ?? '',
        });
        setReceiptFile(null);
        setError('');
        setShowForm(true);
    }

    function cancel() {
        setShowForm(false);
        setEditing(null);
        setForm(emptyForm());
        setReceiptFile(null);
        setError('');
    }

    async function submit(e) {
        e.preventDefault();
        if (form.is_billable && !form.project_id) {
            setError('A billable expense must be tied to a project.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            const fd = toFormData(form, receiptFile);
            if (editingId) {
                fd.append('_method', 'put');
                const updated = await api.postForm(`/api/expenses/${editingId}`, fd);
                setExpenses((current) => current.map((x) => (x.id === editingId ? updated : x)));
            } else {
                const created = await api.postForm('/api/expenses', fd);
                setExpenses((current) => [created, ...current]);
            }
            cancel();
        } catch (err) {
            setError(err.message || 'Could not save this expense.');
        } finally {
            setSaving(false);
        }
    }

    async function remove(expense) {
        if (!confirm(`Delete "${expense.name}"? This can't be undone.`)) return;
        try {
            await api.delete(`/api/expenses/${expense.id}`);
            setExpenses((current) => current.filter((x) => x.id !== expense.id));
            if (expense.id === editingId) cancel();
        } catch (err) {
            alert(err.message || 'Could not delete this expense.');
        }
    }

    async function attach(expense, invoiceId) {
        if (!invoiceId) return;
        setAttachingId(expense.id);
        try {
            const updated = await api.post(`/api/expenses/${expense.id}/attach-to-invoice`, { invoice_id: invoiceId });
            setExpenses((current) => current.map((x) => (x.id === expense.id ? updated : x)));
        } catch (err) {
            alert(err.message || 'Could not attach this expense.');
        } finally {
            setAttachingId(null);
        }
    }

    async function detach(expense) {
        if (!confirm('Detach this expense from its invoice? It will go back to unbilled.')) return;
        try {
            const updated = await api.post(`/api/expenses/${expense.id}/detach-from-invoice`);
            setExpenses((current) => current.map((x) => (x.id === expense.id ? updated : x)));
        } catch (err) {
            alert(err.message || 'Could not detach this expense.');
        }
    }

    const projectById = useMemo(() => Object.fromEntries(projects.map((p) => [p.id, p])), [projects]);

    return (
        <AppLayout>
            <Head title="Expenses" />
            <PageHeader
                title="Expenses"
                actions={<Button onClick={startCreate}>New expense</Button>}
            />

            <div className="metric-grid">
                <MetricCard label={`Spent this month (${metrics.month.count})`} value={formatCurrency(metrics.month.amount)} />
                <MetricCard label={`Spent this year (${metrics.year.count})`} value={formatCurrency(metrics.year.amount)} />
                <MetricCard label={`Ready to bill (${metrics.ready.count})`} value={formatCurrency(metrics.ready.amount)} />
                <MetricCard label={`Billed, awaiting payment (${metrics.billed.count})`} value={formatCurrency(metrics.billed.amount)} />
            </div>

            <div className="filter-bar">
                <input
                    type="search"
                    placeholder="Search expenses&hellip;"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label="Search expenses"
                    className="input filter-bar__search"
                />
                <select
                    value={categoryFilter}
                    onChange={(e) => setCategoryFilter(e.target.value)}
                    aria-label="Category"
                    className="input input--inline"
                >
                    <option value="">All categories</option>
                    <option value="uncategorized">Uncategorized</option>
                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <div className="filter-bar__pills">
                    {STATUS_FILTERS.map((f) => (
                        <button
                            key={f.value}
                            onClick={() => setStatusFilter(f.value)}
                            className={`filter-bar__pill${statusFilter === f.value ? ' filter-bar__pill--active' : ''}`}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>
                <Button variant="secondary" onClick={() => setManaging(true)} className="filter-bar__end">
                    Categories &amp; taxes
                </Button>
            </div>

            {managing && (
                <Drawer onClose={() => setManaging(false)}>
                    <h2 className="drawer__title">Categories &amp; taxes</h2>
                    <CategoryAndTaxManager categories={categories} setCategories={setCategories} taxes={taxes} setTaxes={setTaxes} />
                </Drawer>
            )}

            {showForm && (
                <Drawer
                    onClose={cancel}
                    actions={editing && editable && (
                        <button onClick={() => remove(editing)} title="Delete expense" aria-label="Delete expense" className="icon-btn icon-btn--danger drawer__action">
                            <Trash />
                        </button>
                    )}
                >
                    {editing && (
                        <DrawerByline>
                            <DrawerDate label="Created" date={editing.created_at} />
                            <ExpenseStatusBadge expense={editing} />
                        </DrawerByline>
                    )}
                    <h2 className="drawer__title">{editing ? editing.name : 'New expense'}</h2>
                    {!editable && (
                        <p className="form-hint drawer__section">
                            Billed expenses can't be edited. Detach it from its invoice first.
                        </p>
                    )}
                    <form onSubmit={submit}>
                        {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                        <fieldset disabled={!editable} className="drawer__fieldset">
                            <div className="form-grid">
                                <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input form-grid__full" />
                                <select value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })} className="input">
                                    <option value="">Category&hellip;</option>
                                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="input u-tabular-nums" />
                                <select value={form.tax_id} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} className="input">
                                    <option value="">No tax</option>
                                    {taxes.map((t) => <option key={t.id} value={t.id}>{t.name} ({t.rate}%)</option>)}
                                </select>
                                <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="input" />

                                <select value={form.project_id} onChange={(e) => setForm({ ...form, project_id: e.target.value })} className="input form-grid__full">
                                    <option value="">No project</option>
                                    {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                </select>

                                <Toggle checked={form.is_billable} onChange={(is_billable) => setForm({ ...form, is_billable })} label="Billable to project" />
                                <input
                                    type="number" min="0" step="0.01" placeholder="Markup %"
                                    value={form.markup_percent}
                                    disabled={!form.is_billable}
                                    onChange={(e) => setForm({ ...form, markup_percent: e.target.value })}
                                    className="input u-tabular-nums"
                                />

                            </div>

                            <ReceiptPanel
                                expense={editing}
                                file={receiptFile}
                                onPick={setReceiptFile}
                                onClear={() => setReceiptFile(null)}
                            />

                            <button type="button" onClick={() => setShowAdditional((o) => !o)} className="disclosure expenses__more">
                                {showAdditional ? <CaretDown size={14} /> : <CaretRight size={14} />}
                                Additional fields
                            </button>
                            {/* Read-only (billed) shows them open -- the toggle is disabled with the rest. */}
                            {(showAdditional || !editable) && (
                                <div className="form-grid">
                                    <select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} className="input">
                                        <option value="USD">USD</option>
                                        <option value="CAD">CAD</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                    </select>
                                    <input placeholder="Source label (e.g. bank/card name)" value={form.source_label} onChange={(e) => setForm({ ...form, source_label: e.target.value })} className="input" />
                                    <Toggle checked={form.is_recurring} onChange={(is_recurring) => setForm({ ...form, is_recurring })} label="Recurring expense" />
                                    <select
                                        value={form.recurrence_interval}
                                        disabled={!form.is_recurring}
                                        onChange={(e) => setForm({ ...form, recurrence_interval: e.target.value })}
                                        className="input"
                                    >
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="quarterly">Quarterly</option>
                                        <option value="yearly">Yearly</option>
                                    </select>
                                </div>
                            )}
                        </fieldset>

                        {editable && (
                            <div className="form-actions form-actions--spaced">
                                <Button type="button" variant="secondary" onClick={cancel}>Cancel</Button>
                                <Button type="submit" variant="confirm" disabled={saving}>{editing ? 'Save' : 'Add expense'}</Button>
                            </div>
                        )}
                    </form>
                </Drawer>
            )}

            <div className="card card--flush">
                {filtered.length === 0 ? (
                    <EmptyState text={expenses.length === 0 ? 'No expenses yet.' : 'No expenses match these filters.'} />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Project</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th className="table__cell--end">Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody ref={rowsRef}>
                            {filtered.map((expense) => {
                                const projectDraftInvoices = draftInvoices.filter((inv) => inv.project_id === expense.project_id);
                                return (
                                    <tr key={expense.id} onClick={() => startEdit(expense)} className="table__row--top table__row--link">
                                        <td>
                                            <div className="expenses__name">
                                                {expense.name}
                                                {expense.receipt_url && (
                                                    <a href={expense.receipt_url} target="_blank" rel="noreferrer" title="View receipt" onClick={(e) => e.stopPropagation()} className="icon-btn icon-btn--secondary">
                                                        <PaperclipHorizontal />
                                                    </a>
                                                )}
                                            </div>
                                            <div className="table__note">
                                                {expense.is_billable ? 'Billable' : 'Not billable'}
                                                {expense.source_label ? ` · ${expense.source_label}` : ''}
                                            </div>
                                        </td>
                                        <td className="table__cell--muted">{projectById[expense.project_id]?.name ?? '—'}</td>
                                        <td>{formatDate(expense.date)}</td>
                                        <td><CategoryPill category={expense.category} /></td>
                                        <td><ExpenseStatusBadge expense={expense} /></td>
                                        <td className="table__cell--end table__cell--numeric">{formatCurrency(expense.amount)}</td>
                                        <td className="table__cell--end">
                                            {/* Controls in the row act on their own, without opening the drawer. */}
                                            <div className="table__actions" onClick={(e) => e.stopPropagation()}>
                                                {expense.billing_status === 'unbilled' && expense.is_billable && projectDraftInvoices.length > 0 && (
                                                    <select
                                                        disabled={attachingId === expense.id}
                                                        defaultValue=""
                                                        onChange={(e) => attach(expense, e.target.value)}
                                                        className="expenses__attach"
                                                    >
                                                        <option value="" disabled>Attach to invoice&hellip;</option>
                                                        {projectDraftInvoices.map((inv) => (
                                                            <option key={inv.id} value={inv.id}>Invoice #{inv.invoice_number}</option>
                                                        ))}
                                                    </select>
                                                )}
                                                {expense.billing_status === 'billed' && (
                                                    <button onClick={() => detach(expense)} className="text-action text-action--xs text-action--underline">Detach</button>
                                                )}
                                                {expense.billing_status === 'unbilled' && (
                                                    <button onClick={() => remove(expense)} title="Delete" className="icon-btn icon-btn--danger">
                                                        <Trash />
                                                    </button>
                                                )}
                                                {/* The keyboard way in; the row's own click does the same. */}
                                                <button onClick={() => startEdit(expense)} title="Open expense" aria-label="Open expense" className="row-action">
                                                    <CaretRight size={14} weight="bold" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
