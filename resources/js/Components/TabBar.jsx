export default function TabBar({ tabs, tab, setTab }) {
    return (
        <div className="tabs">
            {tabs.map((t) => (
                <button
                    key={t}
                    onClick={() => setTab(t)}
                    className={`tabs__tab${tab === t ? ' tabs__tab--active' : ''}`}
                >
                    {t}
                </button>
            ))}
        </div>
    );
}
