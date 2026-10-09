// The emoji you've picked lately, most recent first, for the row above the
// full picker. Kept in this browser only; if storage isn't available (a
// private window), there's simply no row.
const KEY = 'chat.recentEmoji';
const MAX = 16;

export function recentEmoji() {
    try {
        const list = JSON.parse(localStorage.getItem(KEY) ?? '[]');
        return Array.isArray(list) ? list.filter((e) => typeof e === 'string').slice(0, MAX) : [];
    } catch {
        return [];
    }
}

export function rememberEmoji(emoji) {
    try {
        localStorage.setItem(KEY, JSON.stringify([emoji, ...recentEmoji().filter((e) => e !== emoji)].slice(0, MAX)));
    } catch {
        // Not kept; nothing else depends on it.
    }
}
