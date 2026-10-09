// Chat message text. Stored as plain text with each mention as a <@id>
// token (App\Models\ChatMessage); typed and edited with mentions as
// "@Full Name". Rendering turns it into React elements -- plain strings,
// which React escapes, plus mention and link elements -- and never HTML.

const TOKEN = /(<@\d+>|https?:\/\/[^\s<>]+)/g;

// The body as parts to render: { type: 'text' | 'mention' | 'link', ... }.
// Line breaks stay in the text (the element keeps white-space: pre-wrap).
export function parseMessage(body, staffById) {
    if (!body) return [];

    return body.split(TOKEN).filter(Boolean).map((part) => {
        const mention = part.match(/^<@(\d+)>$/);
        if (mention) {
            const id = Number(mention[1]);
            return { type: 'mention', id, name: staffById.get(id)?.name ?? 'someone' };
        }
        if (/^https?:\/\//.test(part)) {
            // A sentence's closing punctuation isn't part of the link.
            const [, url, trailing] = part.match(/^(.*?)([.,!?;:)\]'"]*)$/);
            return { type: 'link', url, trailing };
        }
        return { type: 'text', text: part };
    });
}

// A stored body as the composer shows it: <@id> -> "@Name", with the
// mentions it holds (so saving turns them back into tokens).
export function bodyForEditing(body, staffById) {
    const mentions = [];
    const text = (body ?? '').replace(/<@(\d+)>/g, (token, id) => {
        const person = staffById.get(Number(id));
        if (!person) return token;
        if (!mentions.some((m) => m.id === person.id)) mentions.push({ id: person.id, name: person.name });
        return `@${person.name}`;
    });
    return { text, mentions };
}

// What the composer sends: each picked mention's "@Name" -> <@id>, longest
// names first so "@Sam Lee" isn't half-matched by "@Sam". A mention that's
// been edited away is just text.
export function bodyForSaving(text, mentions) {
    return [...mentions]
        .sort((a, b) => b.name.length - a.name.length)
        .reduce((out, m) => out.split(`@${m.name}`).join(`<@${m.id}>`), text);
}

// The "@query" being typed just before the caret, if any: where it
// starts and what's typed so far.
export function mentionQueryAt(text, caret) {
    const match = text.slice(0, caret).match(/(^|\s)@([^\s@]{0,30})$/);
    return match ? { start: caret - match[2].length - 1, query: match[2] } : null;
}
