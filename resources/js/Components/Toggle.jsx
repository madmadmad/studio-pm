// The on/off control app-wide -- used in place of a checkbox for any
// yes/no setting. `className` adds a modifier (e.g. toggle--muted).
export default function Toggle({ checked, onChange, label, disabled = false, className = '' }) {
    return (
        <label className={`toggle ${className}`.trim()}>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
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
