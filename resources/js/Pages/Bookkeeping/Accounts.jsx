import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import EmptyState from '../../Components/EmptyState';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import TabToolbar from '../../Components/TabToolbar';
import Toggle from '../../Components/Toggle';
import AccountDrawer from '../../Components/ledger/AccountDrawer';
import AccountSelect, { groupAccounts } from '../../Components/ledger/AccountSelect';
import { api } from '../../lib/api';
import { useRememberedTab } from '../../lib/useRememberedTab';

const TABS = ['Accounts', 'Mappings'];

// Every account of the chart, a table per group heading. A row opens the
// account to edit; accounts are added under a heading.
function AccountsTab({ accounts, onOpen, onAdd }) {
    const placeholders = accounts.some((a) => a.code_is_placeholder);

    return (
        <>
            <TabToolbar addLabel="Add account" onAdd={onAdd} />
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
                                        <tr key={account.id} onClick={() => onOpen(account)} className="table__row--link">
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

// One mapping table: a record per row, each control saving as it
// changes. `columns` are [field, heading, account type, blank label] for
// an account picker, or [field, heading, 'toggle'] for an on/off.
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
                                            {type === 'toggle' ? (
                                                <Toggle checked={Boolean(row[field])} ariaLabel={`${row.name}: ${heading}`} onChange={(value) => save(row, field, value)} />
                                            ) : (
                                            <AccountSelect
                                                accounts={accounts}
                                                filter={(a) => a.type === type}
                                                value={row[field]}
                                                blankLabel={blankLabel}
                                                label={`${row.name}: ${heading}`}
                                                onChange={(value) => save(row, field, value)}
                                            />
                                            )}
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
                hint={`Where an expense posts, by its category. A category with no account posts to ${fallbacks.expense}. "When billed" is used instead for an expense billed to a client: Advertising billed to a client is client media spend, not our marketing. "Billed as" is where the income goes when it's rebilled on an invoice, and "Taxable" starts that invoice line out taxable (printing).`}
                rows={categories}
                columns={[
                    ['account_id', 'Posts to', 'expense', `${fallbacks.expense} (not mapped)`],
                    ['billable_account_id', 'When billed', 'expense', 'Same account'],
                    ['revenue_account_id', 'Billed as', 'income', 'Service\u2019s or invoice\u2019s'],
                    ['taxable_when_billed', 'Taxable', 'toggle'],
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
// from the app's categories and services to them. Accounts are added,
// renamed and deactivated here (never deleted). Built by
// App\Http\Controllers\Web\LedgerPageController.
export default function Accounts(props) {
    const [tab, setTab] = useRememberedTab('bookkeeping.accounts.tab', TABS, { param: 'tab' });
    const [accounts, setAccounts] = useState(props.accounts);
    // 'new' while adding, an account while editing, null when closed.
    const [editing, setEditing] = useState(null);

    function saved(account) {
        setAccounts((current) => [...current.filter((a) => a.id !== account.id), account].sort((a, b) => a.code.localeCompare(b.code)));
    }

    return (
        <AppLayout>
            <Head title="Chart of accounts" />
            <PageHeader back={{ href: '/bookkeeping', label: 'Bookkeeping' }} title="Chart of accounts" />
            <TabBar tabs={TABS} tab={tab} setTab={setTab} />
            {tab === 'Accounts'
                ? <AccountsTab accounts={accounts} onOpen={setEditing} onAdd={() => setEditing('new')} />
                : <MappingsTab {...props} accounts={accounts} />}
            {editing && (
                <AccountDrawer
                    key={editing === 'new' ? 'new' : editing.id}
                    account={editing === 'new' ? null : editing}
                    accounts={accounts}
                    onSaved={saved}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}
