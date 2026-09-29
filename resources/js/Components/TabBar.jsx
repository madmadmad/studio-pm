// `counts` (optional) maps a tab to a number shown beside its label
// ("Invoices 4"). `size="lg"` for a page where the tabs are the main
// navigation (a project).
export default function TabBar({ tabs, tab, setTab, counts = {}, size }) {
    return (
        <div className={`tabs${size === 'lg' ? ' tabs--lg' : ''}`}>
            {tabs.map((t) => (
                <button
                    key={t}
                    onClick={() => setTab(t)}
                    className={`tabs__tab${tab === t ? ' tabs__tab--active' : ''}`}
                >
                    {t}
                    {counts[t] != null && <span className="count tabs__count">{counts[t]}</span>}
                </button>
            ))}
        </div>
    );
}
