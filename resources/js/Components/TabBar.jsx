// `counts` (optional) maps a tab to a number shown beside its label
// ("Invoices 4").
export default function TabBar({ tabs, tab, setTab, counts = {} }) {
    return (
        <div className="tabs">
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
