// A small segmented switch between ways of viewing a page (List / Board on
// Projects). `views` are { value, label, icon }; the current one is filled.
export default function ViewToggle({ views, value, onChange, label = 'View' }) {
    return (
        <div className="view-toggle" role="group" aria-label={label}>
            {views.map((view) => (
                <button
                    key={view.value}
                    type="button"
                    onClick={() => onChange(view.value)}
                    aria-pressed={view.value === value}
                    title={`${view.label} view`}
                    className={`view-toggle__option${view.value === value ? ' view-toggle__option--active' : ''}`}
                >
                    {view.icon}
                    <span>{view.label}</span>
                </button>
            ))}
        </div>
    );
}
