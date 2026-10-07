// The ledger's accounts, grouped under their headings ("Assets",
// "Operating Expenses"...), for picking one to post to. Headings
// themselves can't be picked, and inactive accounts are left out (unless
// one is the current value). `filter` narrows the accounts offered (a
// type, the balance sheet...). Blank is `blankLabel`, or no blank option
// when it's left out.
export function groupAccounts(accounts) {
    return accounts
        .filter((a) => a.parent_id === null)
        .map((heading) => ({ heading, accounts: accounts.filter((a) => a.parent_id === heading.id) }));
}

export default function AccountSelect({ accounts, filter = () => true, value, onChange, blankLabel, label, className = 'input input--xs', ...props }) {
    const groups = groupAccounts(accounts)
        .map(({ heading, accounts: rows }) => ({
            heading,
            rows: rows.filter((a) => filter(a) && (a.is_active || a.id === value)),
        }))
        .filter(({ rows }) => rows.length > 0);

    return (
        <select
            aria-label={label}
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
            className={className}
            {...props}
        >
            {blankLabel !== undefined && <option value="">{blankLabel}</option>}
            {groups.map(({ heading, rows }) => (
                <optgroup key={heading.id} label={heading.name}>
                    {rows.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </optgroup>
            ))}
        </select>
    );
}

// The accounts money sits in or is owed from -- what a transfer moves
// between.
export const BALANCE_SHEET = ['asset', 'liability', 'equity'];
