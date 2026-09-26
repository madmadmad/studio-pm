// Helpers for HTML saved by Components/RichTextEditor.

// True for rich text with no visible text -- Tiptap saves an emptied
// editor as "<p></p>", not an empty string.
export function isBlankRichText(html) {
    return !html || html.replace(/<[^>]*>/g, '').trim() === '';
}

// Rich text for the editor/viewer from a stored value that may predate
// rich text (task descriptions and proposal item details were plain
// text): plain text is escaped and each line becomes a paragraph, so its
// line breaks survive.
export function toRichText(value) {
    if (isBlankRichText(value)) return '';
    if (/^\s*</.test(value)) return value;
    const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return value.split('\n').map((line) => `<p>${escape(line)}</p>`).join('');
}

// Plain text from rich text, for places that can't show formatting (item
// names, invoice items): one line per paragraph or heading, list items
// prefixed with "- ". Plain text passes through untouched.
export function toPlainText(value) {
    if (isBlankRichText(value)) return '';
    if (!/^\s*</.test(value)) return value;
    const doc = new DOMParser().parseFromString(value, 'text/html');
    doc.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
    doc.querySelectorAll('li').forEach((li) => li.prepend('- '));
    doc.querySelectorAll('p, h1, h2, h3, h4, h5, h6').forEach((el) => el.append('\n'));
    return doc.body.textContent.replace(/\n+$/, '');
}
