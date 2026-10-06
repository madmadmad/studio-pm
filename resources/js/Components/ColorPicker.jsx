import { useEffect, useState } from 'react';
import Button from './Button';

const HEX = /^#?[0-9a-f]{6}$/i;
const DEFAULT = '#CF0034'; // the studio red (BrandPalette::DEFAULT) -- not var(--brand), which is whoever's signed in

// A brand color: the swatch opens the system color picker, the field takes
// a pasted hex code, and the reset goes back to the default (null). Text
// drawn in the color is checked for contrast on the server
// (app/Support/BrandPalette), so any color is allowed here.
export default function ColorPicker({ value, onChange, defaultLabel = 'Use default', id }) {
    const [text, setText] = useState(value ?? '');

    useEffect(() => setText(value ?? ''), [value]);

    function typed(raw) {
        setText(raw);
        if (HEX.test(raw.trim())) onChange(normalize(raw));
    }

    return (
        <div className="color-picker">
            <label className="color-picker__swatch" style={{ backgroundColor: value || DEFAULT }} title="Pick a color">
                <input type="color" value={value || DEFAULT} onChange={(e) => onChange(normalize(e.target.value))} aria-label="Pick a color" />
            </label>
            <input
                id={id}
                value={text}
                onChange={(e) => typed(e.target.value)}
                onBlur={() => setText(value ?? '')}
                placeholder={DEFAULT}
                spellCheck={false}
                maxLength={7}
                aria-label="Hex color"
                className="input color-picker__hex"
            />
            {value && <Button type="button" variant="link-accent" onClick={() => onChange(null)}>{defaultLabel}</Button>}
        </div>
    );
}

function normalize(hex) {
    return ('#' + hex.trim().replace(/^#/, '')).toUpperCase();
}
