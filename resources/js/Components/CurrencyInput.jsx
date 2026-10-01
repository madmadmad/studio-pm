import { useState } from 'react';
import { formatCurrency } from '../lib/format';

// A money field: shows "$4,760.00" at rest, and the plain number while
// it's being typed in (so the cursor isn't fighting a "$" and commas).
// Leaving it rounds to cents. `value` and `onChange(value)` are the plain
// decimal string the form keeps ('' when empty); typed "$" and commas
// (from a paste) are dropped.
export default function CurrencyInput({ value, onChange, className = '', ...props }) {
    const [focused, setFocused] = useState(false);
    const text = value == null ? '' : String(value);
    const number = parseFloat(text);
    const valid = text !== '' && !Number.isNaN(number);

    function change(raw) {
        // Digits and a single decimal point.
        const [whole, ...rest] = raw.replace(/[^0-9.]/g, '').split('.');
        onChange(rest.length ? `${whole}.${rest.join('')}` : whole);
    }

    return (
        <input
            type="text"
            inputMode="decimal"
            autoComplete="off"
            value={focused ? text : (valid ? formatCurrency(number) : '')}
            onFocus={(e) => {
                setFocused(true);
                const input = e.target;
                requestAnimationFrame(() => input.select());
            }}
            onChange={(e) => change(e.target.value)}
            onBlur={() => {
                setFocused(false);
                if (valid && text !== number.toFixed(2)) onChange(number.toFixed(2));
            }}
            className={`${className} u-tabular-nums`.trim()}
            {...props}
        />
    );
}
