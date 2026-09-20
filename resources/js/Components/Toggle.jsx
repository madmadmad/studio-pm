export default function Toggle({ checked, onChange, label }) {
    return (
        <label className="inline-flex items-center gap-2 cursor-pointer select-none text-sm">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative inline-flex h-5 w-9 flex-shrink-0 items-center rounded-full transition-colors ${
                    checked ? 'bg-pine' : 'bg-border'
                }`}
            >
                <span
                    className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                        checked ? 'translate-x-4' : 'translate-x-0.5'
                    }`}
                />
            </button>
            {label && <span>{label}</span>}
        </label>
    );
}
