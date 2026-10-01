// `counts` (optional) maps a tab to a number shown beside its label
// ("Invoices 4"). `size="lg"` for a page where the tabs are the main
// navigation (a project).
// `unread` puts a red count on a tab (unread message threads), in place
// of its grey one.
export default function TabBar({ tabs, tab, setTab, counts = {}, unread = {}, size }) {
    return (
        <div className={`tabs${size === 'lg' ? ' tabs--lg' : ''}`}>
            {tabs.map((t) => (
                <button
                    key={t}
                    onClick={() => setTab(t)}
                    className={`tabs__tab${tab === t ? ' tabs__tab--active' : ''}`}
                >
                    {t}
                    {unread[t] > 0 ? (
                        <span className="count count--unread tabs__count" title={`${unread[t]} unread`}>{unread[t]}</span>
                    ) : (
                        counts[t] != null && <span className="count tabs__count">{counts[t]}</span>
                    )}
                </button>
            ))}
        </div>
    );
}
