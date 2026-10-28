import { useRef, useState } from 'react';
import Button from '../Button';
import CurrencyInput from '../CurrencyInput';
import EntryFormDrawer, { Field, cents } from './EntryFormDrawer';
import { api } from '../../lib/api';
import { formatCurrency, formatDate, todayInAppTimezone } from '../../lib/format';

const COSTS = [
    ['wages', 'Gross wages'],
    ['payroll_taxes', 'Employer payroll taxes'],
    ['retirement_expense', 'Employer retirement contributions'],
];

// What the bank shows leaving checking for the pay run, and the health
// deduction that stayed in it.
const OUT = [
    ['payroll_debit', 'Total Cash Requirement (Data Service)'],
    ['retirement_debit', 'American Funds debit'],
    ['health_deduction', 'Employees’ health insurance deduction'],
];

const AMOUNTS = [...COSTS, ...OUT].map(([key]) => key);

// The report's sections (CashRequirementSummary's keys).
const SECTION_NAMES = {
    EMPLOYEETAXWITHHOLDINGS: 'Employee tax withholdings',
    EMPLOYEEDEDUCTIONS: 'Employee deductions',
    EMPLOYERTAXEXPENSES: 'Employer taxes',
};

// "SIMPIRA" / "SIMP IRA" → "Simp ira": the report's labels, readable.
function label(text) {
    const t = text.trim().toLowerCase();
    return t.charAt(0).toUpperCase() + t.slice(1);
}

// One pay run from Data Service's Cash Requirement Summary. "Read from
// report" uploads the PDF; the server checks it adds up and fills in every
// amount, which then stay as the report has them (the report is posted
// with the entry and kept). Without a report the amounts are typed in from
// it. Either way the costs must equal what left checking plus the health
// deduction before it can post.
export default function PayrollDrawer({ onPosted, onClose }) {
    const [form, setForm] = useState({
        entry_date: todayInAppTimezone(),
        memo: '',
        ...Object.fromEntries(AMOUNTS.map((key) => [key, ''])),
    });
    const [report, setReport] = useState(null); // { file, read }
    const [reading, setReading] = useState(false);
    const [readError, setReadError] = useState('');
    const [allowDuplicate, setAllowDuplicate] = useState(false);
    const fileRef = useRef(null);

    const costs = COSTS.reduce((sum, [key]) => sum + cents(form[key]), 0);
    const out = OUT.reduce((sum, [key]) => sum + cents(form[key]), 0);
    const balanced = costs > 0 && costs === out;
    const duplicate = report?.read.already_entered;

    async function read(file) {
        if (!file) return;
        setReading(true);
        setReadError('');
        const body = new FormData();
        body.append('report', file);
        try {
            const read = await api.postForm('/api/journal-entries/payroll/read', body);
            setReport({ file, read });
            setAllowDuplicate(false);
            setForm({ entry_date: read.entry_date, memo: read.memo, ...read.amounts });
        } catch (err) {
            setReadError(err.message || 'That report couldn’t be read.');
        } finally {
            setReading(false);
        }
    }

    function removeReport() {
        setReport(null);
        setReadError('');
    }

    function payload() {
        if (!report) return form;
        const body = new FormData();
        Object.entries(form).forEach(([key, value]) => body.append(key, value ?? ''));
        body.append('report', report.file);
        if (allowDuplicate) body.append('allow_duplicate', '1');
        return body;
    }

    const field = ([key, text]) => (
        <Field key={key} label={text}>
            <CurrencyInput
                value={form[key]}
                onChange={(value) => setForm({ ...form, [key]: value })}
                readOnly={Boolean(report)}
                className="input input--xs"
            />
        </Field>
    );

    const sections = report?.read.report.sections;

    return (
        <EntryFormDrawer
            title="Payroll"
            hint="One pay run from Data Service’s Cash Requirement Summary. Read the PDF to fill everything in, or type the amounts from it."
            endpoint="/api/journal-entries/payroll"
            canPost={balanced && (!duplicate || allowDuplicate)}
            payload={payload}
            onPosted={onPosted}
            onClose={onClose}
        >
            <div className="drawer__section">
                <input ref={fileRef} type="file" accept="application/pdf,.pdf" hidden onChange={(e) => { read(e.target.files[0]); e.target.value = ''; }} />
                {report ? (
                    <p className="form-hint">
                        Read from <strong>{report.file.name}</strong>, check date {formatDate(report.read.entry_date)}. The amounts are the report’s.{' '}
                        <button type="button" className="text-action text-action--sm" onClick={removeReport}>Type them in instead</button>
                    </p>
                ) : (
                    <Button variant="secondary" onClick={() => fileRef.current?.click()} disabled={reading}>
                        {reading ? 'Reading…' : 'Read from report (PDF)'}
                    </Button>
                )}
                {readError && <div className="form-message form-message--error">{readError}</div>}
            </div>

            {duplicate && (
                <div className="form-message form-message--error drawer__section">
                    The pay run for this check date is already entered (entry #{duplicate.entry_number}).{' '}
                    <label>
                        <input type="checkbox" checked={allowDuplicate} onChange={(e) => setAllowDuplicate(e.target.checked)} /> Post it again anyway
                    </label>
                </div>
            )}

            <div className="form-grid drawer__section">
                <Field label="Pay date">
                    <input required type="date" value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} className="input input--xs" />
                </Field>
                <Field label="Memo">
                    <input placeholder="e.g. Payroll, check date Oct 15" value={form.memo} onChange={(e) => setForm({ ...form, memo: e.target.value })} className="input input--xs" />
                </Field>
            </div>

            <div className="section-label">Costs</div>
            <div className="form-grid drawer__section">{COSTS.map(field)}</div>

            <div className="section-label">Out of checking, and the health deduction</div>
            <div className="form-grid drawer__section">{OUT.map(field)}</div>

            <div className="detail-list drawer__section">
                {[
                    ['Costs', costs],
                    ['Out of checking + health deduction', out],
                ].map(([text, amount]) => (
                    <div key={text} className="detail-list__item detail-list__item--split">
                        <span className="detail-list__title">{text}</span>
                        <span className="u-tabular-nums">{formatCurrency(amount / 100)}</span>
                    </div>
                ))}
            </div>
            {costs > 0 && !balanced && (
                <div className="form-message form-message--error drawer__section">
                    Off by {formatCurrency(Math.abs(costs - out) / 100)}. Gross wages plus the employer’s taxes and retirement contributions should equal the two debits plus the health deduction.
                </div>
            )}

            {sections && (
                <>
                    <div className="section-label">What the report says</div>
                    <div className="detail-list drawer__section">
                        <div className="detail-list__item detail-list__item--split">
                            <span className="detail-list__title">Net payroll</span>
                            <span className="u-tabular-nums">{formatCurrency(report.read.report.net_payroll_cents / 100)}</span>
                        </div>
                        {Object.entries(sections.cash).map(([name, section]) => (
                            <div key={`cash-${name}`} className="detail-list__item detail-list__item--split">
                                <span className="detail-list__title">{SECTION_NAMES[name] ?? name}</span>
                                <span className="u-tabular-nums">{formatCurrency(section.subtotal / 100)}</span>
                            </div>
                        ))}
                        <div className="detail-list__item detail-list__item--split">
                            <span className="detail-list__title"><strong>Total cash requirement</strong></span>
                            <span className="u-tabular-nums"><strong>{formatCurrency(report.read.report.total_cash_requirement_cents / 100)}</strong></span>
                        </div>
                        {Object.entries(sections.other).flatMap(([name, section]) => section.lines.map(([text, amount]) => {
                            const workersComp = /WORKERS\s*COMP/i.test(text);
                            return (
                                <div key={`other-${name}-${text}`} className="detail-list__item detail-list__item--split">
                                    <span className="detail-list__title">{label(text)}{workersComp ? ' (paid separately, not entered)' : ''}</span>
                                    <span className="u-tabular-nums">{formatCurrency(amount / 100)}</span>
                                </div>
                            );
                        }))}
                    </div>
                </>
            )}
        </EntryFormDrawer>
    );
}
