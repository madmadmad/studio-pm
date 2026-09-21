import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { CaretDown, CaretRight, PaperclipHorizontal, PencilSimple, Trash, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { ExpenseStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

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
    if (!category) return <span className="text-shadow-grey">&mdash;</span>;
    return (
        <span
            className="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded"
            style={{ backgroundColor: `${category.color}22`, color: category.color }}
        >
            {category.name}
        </span>
    );
}

function CategoryAndTaxManager({ categories, setCategories, taxes, setTaxes }) {
    const [open, setOpen] = useState(false);
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
        <div className="mb-6">
            <button onClick={() => setOpen((o) => !o)} className="flex items-center gap-1 text-sm text-shadow-grey hover:text-gunmetal">
                {open ? <CaretDown size={14} /> : <CaretRight size={14} />}
                Manage categories &amp; taxes
            </button>
            {open && (
                <div className="mt-3 grid grid-cols-2 gap-4">
                    <div className="bg-white rounded-lg border border-border p-4">
                        <h3 className="text-sm font-semibold mb-3">Categories</h3>
                        <div className="flex flex-col gap-2 mb-3">
                            {categories.map((category) => (
                                <div key={category.id} className="flex items-center justify-between text-sm">
                                    <CategoryPill category={category} />
                                    <button onClick={() => removeCategory(category)} className="text-shadow-grey hover:text-fuchsia">
                                        <X size={14} />
                                    </button>
                                </div>
                            ))}
                        </div>
                        <form onSubmit={addCategory} className="flex gap-2">
                            <input type="color" value={categoryForm.color} onChange={(e) => setCategoryForm({ ...categoryForm, color: e.target.value })} className="w-9 h-9 border border-border rounded" />
                            <input placeholder="New category" value={categoryForm.name} onChange={(e) => setCategoryForm({ ...categoryForm, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm flex-1" />
                            <button type="submit" className="bg-gunmetal text-white text-sm font-medium px-3 py-1.5 rounded">Add</button>
                        </form>
                    </div>
                    <div className="bg-white rounded-lg border border-border p-4">
                        <h3 className="text-sm font-semibold mb-3">Taxes</h3>
                        <div className="flex flex-col gap-2 mb-3">
                            {taxes.map((tax) => (
                                <div key={tax.id} className="flex items-center justify-between text-sm">
                                    <span>{tax.name} <span className="text-shadow-grey tabular-nums">({tax.rate}%)</span></span>
                                    <button onClick={() => removeTax(tax)} className="text-shadow-grey hover:text-fuchsia">
                                        <X size={14} />
                                    </button>
                                </div>
                            ))}
                        </div>
                        <form onSubmit={addTax} className="flex gap-2">
                            <input placeholder="Tax name" value={taxForm.name} onChange={(e) => setTaxForm({ ...taxForm, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm flex-1" />
                            <input type="number" min="0" max="100" step="0.01" placeholder="Rate %" value={taxForm.rate} onChange={(e) => setTaxForm({ ...taxForm, rate: e.target.value })} className="border border-border rounded px-3 py-2 text-sm w-24 tabular-nums" />
                            <button type="submit" className="bg-gunmetal text-white text-sm font-medium px-3 py-1.5 rounded">Add</button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function ExpensesIndex({ expenses: expensesProp, categories: categoriesProp, taxes: taxesProp, projects, draftInvoices }) {
    const [expenses, setExpenses] = useState(expensesProp);
    const [categories, setCategories] = useState(categoriesProp);
    const [taxes, setTaxes] = useState(taxesProp);
    const [search, setSearch] = useState('');
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState(null);
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
        if (!search.trim()) return expenses;
        const q = search.trim().toLowerCase();
        return expenses.filter((e) => e.name.toLowerCase().includes(q));
    }, [expenses, search]);

    function startCreate() {
        setEditingId(null);
        setForm(emptyForm());
        setReceiptFile(null);
        setError('');
        setShowForm(true);
    }

    function startEdit(expense) {
        setEditingId(expense.id);
        setForm({
            name: expense.name,
            amount: expense.amount,
            currency: expense.currency,
            category_id: expense.category_id ?? '',
            project_id: expense.project_id ?? '',
            is_billable: expense.is_billable,
            markup_percent: expense.markup_percent,
            tax_id: expense.tax_id ?? '',
            date: expense.date,
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
        setEditingId(null);
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
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Expenses</h1>
                <button onClick={startCreate} className="bg-gunmetal text-white text-sm font-medium px-3 py-1.5 rounded">
                    New expense
                </button>
            </div>
            <p className="text-sm text-shadow-grey mb-4">Track costs, mark them billable to a project, and attach them to an invoice with markup applied.</p>

            <CategoryAndTaxManager categories={categories} setCategories={setCategories} taxes={taxes} setTaxes={setTaxes} />

            {showForm && (
                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-6">
                    {error && <div className="text-sm text-fuchsia mb-3">{error}</div>}
                    <div className="grid grid-cols-2 gap-3">
                        <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
                        <select value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                            <option value="">Category&hellip;</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="border border-border rounded px-3 py-2 text-sm tabular-nums" />
                        <select value={form.tax_id} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                            <option value="">No tax</option>
                            {taxes.map((t) => <option key={t.id} value={t.id}>{t.name} ({t.rate}%)</option>)}
                        </select>
                        <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />

                        <select value={form.project_id} onChange={(e) => setForm({ ...form, project_id: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2">
                            <option value="">No project</option>
                            {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>

                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.is_billable} onChange={(e) => setForm({ ...form, is_billable: e.target.checked })} />
                            Billable to project
                        </label>
                        <input
                            type="number" min="0" step="0.01" placeholder="Markup %"
                            value={form.markup_percent}
                            disabled={!form.is_billable}
                            onChange={(e) => setForm({ ...form, markup_percent: e.target.value })}
                            className="border border-border rounded px-3 py-2 text-sm tabular-nums disabled:opacity-50"
                        />

                        <label className="col-span-2 flex items-center gap-2 text-sm cursor-pointer">
                            <PaperclipHorizontal size={16} className="text-shadow-grey" />
                            Add receipt image
                            <input type="file" accept="image/*,.pdf" onChange={(e) => setReceiptFile(e.target.files?.[0] ?? null)} className="text-sm" />
                        </label>
                    </div>

                    <button type="button" onClick={() => setShowAdditional((o) => !o)} className="flex items-center gap-1 text-sm text-shadow-grey hover:text-gunmetal mt-4 mb-2">
                        {showAdditional ? <CaretDown size={14} /> : <CaretRight size={14} />}
                        Additional fields
                    </button>
                    {showAdditional && (
                        <div className="grid grid-cols-2 gap-3">
                            <select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                                <option value="USD">USD</option>
                                <option value="CAD">CAD</option>
                                <option value="EUR">EUR</option>
                                <option value="GBP">GBP</option>
                            </select>
                            <input placeholder="Source label (e.g. bank/card name)" value={form.source_label} onChange={(e) => setForm({ ...form, source_label: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.is_recurring} onChange={(e) => setForm({ ...form, is_recurring: e.target.checked })} />
                                Recurring expense
                            </label>
                            <select
                                value={form.recurrence_interval}
                                disabled={!form.is_recurring}
                                onChange={(e) => setForm({ ...form, recurrence_interval: e.target.value })}
                                className="border border-border rounded px-3 py-2 text-sm disabled:opacity-50"
                            >
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                    )}

                    <div className="flex gap-2 justify-end mt-4">
                        <button type="button" onClick={cancel} className="text-sm px-3 py-1.5 rounded text-shadow-grey">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-fern text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save</button>
                    </div>
                </form>
            )}

            <input
                placeholder="Search expenses by name&hellip;"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="border border-border rounded px-3 py-2 text-sm mb-4 w-full max-w-sm"
            />

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {filtered.length === 0 ? (
                    <EmptyState text="No expenses found." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-shadow-grey">
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Project</th>
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Category</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium text-right">Amount</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.map((expense) => {
                                const projectDraftInvoices = draftInvoices.filter((inv) => inv.project_id === expense.project_id);
                                return (
                                    <tr key={expense.id} className="border-b border-border last:border-b-0 align-top">
                                        <td className="px-4 py-3">
                                            <div className="font-medium flex items-center gap-2">
                                                {expense.name}
                                                {expense.receipt_url && (
                                                    <a href={expense.receipt_url} target="_blank" rel="noreferrer" title="View receipt" className="text-shadow-grey hover:text-gunmetal">
                                                        <PaperclipHorizontal size={14} />
                                                    </a>
                                                )}
                                            </div>
                                            <div className="text-xs text-shadow-grey">
                                                {expense.is_billable ? 'Billable' : 'Not billable'}
                                                {expense.source_label ? ` · ${expense.source_label}` : ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-shadow-grey">{projectById[expense.project_id]?.name ?? '—'}</td>
                                        <td className="px-4 py-3">{formatDate(expense.date)}</td>
                                        <td className="px-4 py-3"><CategoryPill category={expense.category} /></td>
                                        <td className="px-4 py-3"><ExpenseStatusBadge expense={expense} /></td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatCurrency(expense.amount)}</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-3">
                                                {expense.billing_status === 'unbilled' && expense.is_billable && projectDraftInvoices.length > 0 && (
                                                    <select
                                                        disabled={attachingId === expense.id}
                                                        defaultValue=""
                                                        onChange={(e) => attach(expense, e.target.value)}
                                                        className="border border-border rounded px-2 py-1 text-xs"
                                                    >
                                                        <option value="" disabled>Attach to invoice&hellip;</option>
                                                        {projectDraftInvoices.map((inv) => (
                                                            <option key={inv.id} value={inv.id}>Invoice #{inv.invoice_number}</option>
                                                        ))}
                                                    </select>
                                                )}
                                                {expense.billing_status === 'billed' && (
                                                    <button onClick={() => detach(expense)} className="text-xs text-shadow-grey hover:text-gunmetal underline">Detach</button>
                                                )}
                                                {expense.billing_status === 'unbilled' && (
                                                    <>
                                                        <button onClick={() => startEdit(expense)} title="Edit" className="text-fern hover:text-fern/70">
                                                            <PencilSimple size={16} />
                                                        </button>
                                                        <button onClick={() => remove(expense)} title="Delete" className="text-shadow-grey hover:text-fuchsia">
                                                            <Trash size={16} />
                                                        </button>
                                                    </>
                                                )}
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
