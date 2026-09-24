export default function Toggle({ checked, onChange, label }) {
    return (
        <label className="toggle">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`toggle__switch${checked ? ' toggle__switch--on' : ''}`}
            >
                <span className="toggle__knob" />
            </button>
            {label && <span>{label}</span>}
        </label>
    );
}
