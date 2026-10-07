import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Books, FileCsv, FileText } from '@phosphor-icons/react';

const REPORTS = [
    { href: '/bookkeeping/profit-loss', label: 'Profit & loss statement' },
    { href: '/bookkeeping/sales-tax', label: 'Sales tax report' },
    { href: '/bookkeeping/invoice-categories', label: 'Invoices by category' },
    { href: '/bookkeeping/hosting', label: 'Hosting profitability' },
];

// Not year-based, so linked without ?year.
const LEDGER = [
    { href: '/bookkeeping/journal', label: 'Journal' },
    { href: '/bookkeeping/accounts', label: 'Chart of accounts' },
];

const DOWNLOADS = [
    { href: '/bookkeeping/profit-loss.csv', label: 'Profit & loss CSV' },
    { href: '/bookkeeping/invoices.csv', label: 'Invoices CSV' },
    { href: '/bookkeeping/expenses.csv', label: 'Expenses CSV' },
];

// Bookkeeping's reports in one card: the year to run them for, the report
// pages, and the CSV downloads for an accountant.
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
                <div>
                    <div className="section-label section-label--ruled">Reports</div>
                    <ul className="financial-reports__list">
                        {REPORTS.map((r) => (
                            <li key={r.href}>
                                <Link href={`${r.href}?year=${year}`} className="financial-reports__link">
                                    <FileText /> {r.label}
                                </Link>
                            </li>
                        ))}
                        {LEDGER.map((r) => (
                            <li key={r.href}>
                                <Link href={r.href} className="financial-reports__link">
                                    <Books /> {r.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
                <div>
                    <div className="section-label section-label--ruled">Downloads</div>
                    <ul className="financial-reports__list">
                        {DOWNLOADS.map((d) => (
                            <li key={d.href}>
                                <a href={`${d.href}?year=${year}`} className="financial-reports__link">
                                    <FileCsv /> {d.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}
