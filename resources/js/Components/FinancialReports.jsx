import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Books, FileCsv, FileText } from '@phosphor-icons/react';
import { todayInAppTimezone } from '../lib/format';

// Each group: the links, and how a link reaches the chosen year.
const GROUPS = [
    {
        title: 'Reports',
        icon: FileText,
        links: [
            { href: (year) => `/bookkeeping/profit-loss?year=${year}`, label: 'Profit & loss statement' },
            { href: (year) => `/bookkeeping/sales-tax?year=${year}`, label: 'Sales tax report' },
            { href: (year) => `/bookkeeping/invoice-categories?year=${year}`, label: 'Invoices by category' },
            { href: (year) => `/bookkeeping/hosting?year=${year}`, label: 'Hosting profitability' },
        ],
    },
    {
        // Built from the journal. They open on the year and take any dates
        // from there.
        title: 'Ledger reports',
        icon: Books,
        links: [
            { href: (year) => `/bookkeeping/ledger/profit-loss?from=${year}-01-01&to=${year}-12-31`, label: 'Profit & loss' },
            { href: (year) => `/bookkeeping/ledger/expenses-by-month?from=${year}-01-01&to=${year}-12-31`, label: 'Expenses by month' },
            { href: (year) => `/bookkeeping/ledger/balance-sheet?to=${yearEnd(year)}`, label: 'Balance sheet' },
            { href: (year) => `/bookkeeping/ledger/trial-balance?to=${yearEnd(year)}`, label: 'Trial balance' },
            { href: (year) => `/bookkeeping/ledger/general-ledger?from=${year}-01-01&to=${year}-12-31`, label: 'General ledger' },
        ],
    },
    {
        title: 'Downloads',
        icon: FileCsv,
        download: true,
        links: [
            { href: (year) => `/bookkeeping/profit-loss.csv?year=${year}`, label: 'Profit & loss CSV' },
            { href: (year) => `/bookkeeping/invoices.csv?year=${year}`, label: 'Invoices CSV' },
            { href: (year) => `/bookkeeping/expenses.csv?year=${year}`, label: 'Expenses CSV' },
        ],
    },
];

// The year's last day, or today for this year (a balance sheet on a day
// still to come reads the same as today's).
function yearEnd(year) {
    const today = todayInAppTimezone();
    return Number(today.slice(0, 4)) === year ? today : `${year}-12-31`;
}

// Bookkeeping's reports in one card: the year to run them for, then the
// year-based reports, the ledger's reports, and the CSV downloads for an
// accountant, side by side.
export default function FinancialReports({ years }) {
    const [year, setYear] = useState(years[0]);

    return (
        <div className="financial-reports">
            <div className="financial-reports__head">
                <div className="financial-reports__title">Financial reports</div>
                <select value={year} onChange={(e) => setYear(Number(e.target.value))} aria-label="Year" className="input input--inline">
                    {years.map((y) => <option key={y} value={y}>{y}</option>)}
                </select>
            </div>
            <div className="financial-reports__columns">
                {GROUPS.map(({ title, icon: Icon, download, links }) => (
                    <div key={title}>
                        <div className="section-label section-label--ruled">{title}</div>
                        <ul className="financial-reports__list">
                            {links.map((link) => (
                                <li key={link.label}>
                                    {download ? (
                                        <a href={link.href(year)} className="financial-reports__link"><Icon /> {link.label}</a>
                                    ) : (
                                        <Link href={link.href(year)} className="financial-reports__link"><Icon /> {link.label}</Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}
