// Text that's been typed but not yet saved, kept in this browser so a
// reload (a new deploy), a sign-out or a crash never loses it: the new
// to-do line, a to-do's edit, a chat message being written. Keyed per
// person and per thing; cleared once the server has it. Storage can be
// unavailable (a private window) -- then it's simply not kept.

const PREFIX = 'studio:draft:';

export function readDraft(key, fallback = null) {
    try {
        const raw = window.localStorage.getItem(PREFIX + key);
        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function writeDraft(key, value) {
    try {
        if (value === null || value === '' || value === undefined) {
            window.localStorage.removeItem(PREFIX + key);
        } else {
            window.localStorage.setItem(PREFIX + key, JSON.stringify(value));
        }
    } catch {
        // Not kept this time.
    }
}

export function clearDraft(key) {
    writeDraft(key, null);
}
