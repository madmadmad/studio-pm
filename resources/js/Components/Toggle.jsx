// The on/off control app-wide -- used in place of a checkbox for any
// yes/no setting. `className` adds a modifier (e.g. toggle--muted).
// `ariaLabel` names a toggle that has no visible label (one in a table
// row, under a column heading).
export default function Toggle({ checked, onChange, label, ariaLabel, disabled = false, className = '' }) {
    return (
        <label className={`toggle ${className}`.trim()}>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={ariaLabel}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`toggle__switch${checked ? ' toggle__switch--on' : ''}`}
            >
                <span className="toggle__knob" />
            </button>
            {label && <span>{label}</span>}
        </label>
    );
}
