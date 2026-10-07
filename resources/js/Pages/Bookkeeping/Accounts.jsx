import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import EmptyState from '../../Components/EmptyState';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import { api } from '../../lib/api';
import { useRememberedTab } from '../../lib/useRememberedTab';

const TABS = ['Accounts', 'Mappings'];

// The headings (accounts with nothing above them), each with the accounts
// under it, in code order.
function groupAccounts(accounts) {
    return accounts
        .filter((a) => a.parent_id === null)
        .map((heading) => ({ heading, accounts: accounts.filter((a) => a.parent_id === heading.id) }));
}

// Every account of the chart, a table per group heading.
function AccountsTab({ accounts }) {
    const placeholders = accounts.some((a) => a.code_is_placeholder);

    return (
        <>
            {placeholders && (
                <p className="form-hint page-section page-section--tight">
                    Account codes are placeholders until our CPA supplies a chart of accounts.
                </p>
            )}
            {groupAccounts(accounts).map(({ heading, accounts: rows }) => (
                <div key={heading.id}>
                    <div className="section-label section-label--ruled">{heading.name}</div>
                    <div className="card card--flush page-section">
                        {rows.length === 0 ? (
                            <EmptyState text="No accounts under this heading." />
                        ) : (
                            <table className="table chart-of-accounts__table">
                                <colgroup>
                                    <col className="chart-of-accounts__code" />
                                    <col className="chart-of-accounts__name" />
                                    <col />
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Account</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((account) => (
                                        <tr key={account.id}>
                                            <td className="table__cell--muted u-tabular-nums">{account.code}</td>
                                            <td className="table__cell--strong">
                                                <span className="table__group">
                                                    {account.name}
                                                    {!account.is_active && <Badge tone="neutral" label="Inactive" />}
                                                </span>
                                            </td>
                                            <td className="table__cell--muted">{account.description}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            ))}
        </>
    );
}

// A select of the postable accounts of one type, grouped under their
// headings. Blank is the fallback, named so it's clear what happens.
function AccountSelect({ accounts, type, value, blankLabel, onChange, label }) {
    const groups = groupAccounts(accounts.filter((a) => a.type === type))
        .map(({ heading, accounts: rows }) => ({ heading, rows: rows.filter((a) => a.is_active || a.id === value) }))
        .filter(({ rows }) => rows.length > 0);

    return (
        <select
            aria-label={label}
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
            className="input input--xs"
        >
            <option value="">{blankLabel}</option>
            {groups.map(({ heading, rows }) => (
                <optgroup key={heading.id} label={heading.name}>
                    {rows.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </optgroup>
            ))}
        </select>
    );
}

// One mapping table: a record per row, each account picker saving as it
// changes. `columns` are [field, heading, account type, blank label].
function MappingTable({ title, hint, rows, columns, accounts, endpoint, onSaved }) {
    const [error, setError] = useState('');

    async function save(row, field, value) {
        setError('');
        try {
            onSaved(await api.patch(`${endpoint}/${row.id}`, { [field]: value }));
        } catch (err) {
            setError(err.message || 'Could not save that mapping.');
        }
    }

    return (
        <div>
            <div className="section-label section-label--ruled">{title}</div>
            <p className="form-hint page-section page-section--tight">{hint}</p>
            {error && <div className="form-message form-message--error page-section page-section--tight">{error}</div>}
            <div className="card card--flush page-section">
                {rows.length === 0 ? (
                    <EmptyState text={`No ${title.toLowerCase()} yet.`} />
                ) : (
                    <table className="table chart-of-accounts__table">
                        <colgroup>
                            <col className="chart-of-accounts__name" />
                            {columns.map(([field]) => <col key={field} />)}
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Name</th>
                                {columns.map(([field, heading]) => <th key={field}>{heading}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td className="table__cell--strong">{row.name}</td>
                                    {columns.map(([field, heading, type, blankLabel]) => (
                                        <td key={field} className="table__cell--tight">
                                            <AccountSelect
                                                accounts={accounts}
                                                type={type}
                                                value={row[field]}
                                                blankLabel={blankLabel}
                                                label={`${row.name}: ${heading}`}
                                                onChange={(value) => save(row, field, value)}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}

// Swap a saved row into its list.
function replaceIn(setter) {
    return (saved) => setter((current) => current.map((row) => (row.id === saved.id ? { ...row, ...saved } : row)));
}

function MappingsTab({ accounts, fallbacks, ...props }) {
    const [categories, setCategories] = useState(props.expenseCategories);
    const [services, setServices] = useState(props.services);
    const [invoiceCategories, setInvoiceCategories] = useState(props.invoiceCategories);

    return (
        <>
            <MappingTable
                title="Expense categories"
                hint={`Where an expense posts, by its category. A category with no account posts to ${fallbacks.expense}. "When billed" is used instead for an expense billed to a client: Advertising billed to a client is client media spend, not our marketing.`}
                rows={categories}
                columns={[
                    ['account_id', 'Posts to', 'expense', `${fallbacks.expense} (not mapped)`],
                    ['billable_account_id', 'When billed', 'expense', 'Same account'],
                ]}
                accounts={accounts}
                endpoint="/api/account-mappings/expense-categories"
                onSaved={replaceIn(setCategories)}
            />
            <MappingTable
                title="Services"
                hint={`Where income billed for a service posts. With none, it's the invoice category's account, then ${fallbacks.revenue}.`}
                rows={services}
                columns={[['revenue_account_id', 'Revenue account', 'income', 'Invoice category’s, or the default']]}
                accounts={accounts}
                endpoint="/api/account-mappings/services"
                onSaved={replaceIn(setServices)}
            />
            <MappingTable
                title="Invoice categories"
                hint={`Where an invoice's income posts when its lines don't say otherwise. Project work (no category) is ${fallbacks.revenue}.`}
                rows={invoiceCategories}
                columns={[['revenue_account_id', 'Revenue account', 'income', `${fallbacks.revenue} (default)`]]}
                accounts={accounts}
                endpoint="/api/account-mappings/invoice-categories"
                onSaved={replaceIn(setInvoiceCategories)}
            />
        </>
    );
}

// Bookkeeping > Chart of accounts: the ledger's accounts, and the mapping
// from the app's categories and services to them. Accounts are read-only
// here for now (adding, renaming and deactivating come with the journal
// screens). Built by App\Http\Controllers\Web\LedgerPageController.
export default function Accounts(props) {
    const [tab, setTab] = useRememberedTab('bookkeeping.accounts.tab', TABS, { param: 'tab' });

    return (
        <AppLayout>
            <Head title="Chart of accounts" />
            <PageHeader back={{ href: '/bookkeeping', label: 'Bookkeeping' }} title="Chart of accounts" />
            <TabBar tabs={TABS} tab={tab} setTab={setTab} />
            {tab === 'Accounts' ? <AccountsTab accounts={props.accounts} /> : <MappingsTab {...props} />}
        </AppLayout>
    );
}
