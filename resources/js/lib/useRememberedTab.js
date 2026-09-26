import { useState } from 'react';

// A tabbed page's current tab, remembered in the browser under
// `storageKey` so coming back to the page (any project, any client) lands
// on the last tab used. Falls back to the first tab when the saved one
// isn't offered (e.g. a manager-only tab for a team member). Storage can
// be unavailable (private windows), so remembering is best-effort.
export function useRememberedTab(storageKey, tabs) {
    const [tab, setTabState] = useState(() => {
        try {
            const saved = window.localStorage.getItem(storageKey);
            return tabs.includes(saved) ? saved : tabs[0];
        } catch {
            return tabs[0];
        }
    });

    function setTab(next) {
        setTabState(next);
        try {
            window.localStorage.setItem(storageKey, next);
        } catch {
            // Not remembered this time; the tab still switches.
        }
    }

    return [tabs.includes(tab) ? tab : tabs[0], setTab];
}
