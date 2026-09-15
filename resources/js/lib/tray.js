const KEY = 'studio-pm:tray';

export function getTray() {
    try {
        return JSON.parse(sessionStorage.getItem(KEY)) || [];
    } catch {
        return [];
    }
}

export function setTray(items) {
    sessionStorage.setItem(KEY, JSON.stringify(items));
}

export function addToTray(item) {
    const tray = getTray();
    tray.push(item);
    setTray(tray);
    return tray;
}

export function clearTray() {
    sessionStorage.removeItem(KEY);
}
