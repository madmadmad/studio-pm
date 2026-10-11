import { formatMonth } from '../lib/format';

// A month's heading row in a table grouped by month (Invoices, Expenses):
// "October 2026" on the left, the month's figures (children) on the
// right. `month` is 'YYYY-MM', or '' for rows with no date. Styled by
// .month-divider.
export default function MonthDivider({ month, colSpan, emptyLabel = 'No date', children }) {
    return (
        <tr className="month-divider">
            <td colSpan={colSpan}>
                <div className="month-divider__bar">
                    <span className="month-divider__label">{month ? formatMonth(month) : emptyLabel}</span>
                    <span className="month-divider__figures">{children}</span>
                </div>
            </td>
        </tr>
    );
}
