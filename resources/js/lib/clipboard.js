// navigator.clipboard only exists in a "secure context" -- HTTPS, or
// literally localhost/127.0.0.1. A custom local dev domain like
// studio-pm.test served over plain HTTP doesn't qualify, so
// navigator.clipboard is undefined there and calling it throws before
// anything happens. Fall back to the legacy execCommand approach, which
// works regardless of context.
export async function copyToClipboard(text) {
    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // fall through to the legacy path below
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    let succeeded = false;
    try {
        succeeded = document.execCommand('copy');
    } catch {
        succeeded = false;
    }

    document.body.removeChild(textarea);
    return succeeded;
}
