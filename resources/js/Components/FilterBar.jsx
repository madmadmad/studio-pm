// The search-and-pills row above a list, on its subtle card: a search
// field and a row of filter pills, the chosen one red. `filters` is
// [{ value, label }]; `value`/`onChange` the chosen one; `children` (a
// second set of pills) follow them. (The staff lists build the same
// .filter-bar inline, with extras of their own.)
export default function FilterBar({ search, onSearch, placeholder, label, filters, value, onChange, children }) {
    return (
        <div className="filter-bar">
            <input
                type="search"
                placeholder={placeholder}
                value={search}
                onChange={(e) => onSearch(e.target.value)}
                aria-label={label}
                className="input filter-bar__search"
            />
            {filters.length > 1 && (
                <div className="filter-bar__pills">
                    {filters.map((f) => (
                        <button
                            key={f.value}
                            type="button"
                            onClick={() => onChange(f.value)}
                            className={`filter-bar__pill${value === f.value ? ' filter-bar__pill--active' : ''}`}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>
            )}
            {children}
        </div>
    );
}
