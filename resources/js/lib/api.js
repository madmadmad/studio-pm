import { checkSession, isSignedOut, report, sessionStarted } from './session';

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
}

// What to say when the server gives no message of its own.
function failureMessage(status) {
    if (status === 413) return 'That file is too large to upload.';
    return `Request failed (${status})`;
}

const SIGNED_OUT_MESSAGE = 'You’ve been signed out, so this wasn’t saved. Sign in again to carry on.';

// A request the session refused (401, or 419 for a stale CSRF token) or the
// server failed (5xx): noted for the server's log (lib/session.js).
function noteFailure(method, url, status) {
    if (status === 401 || status === 419 || status >= 500) {
        report('request_failed', { method, path: String(url).split('?')[0], status });
    }
}

// A 419 is usually a CSRF token that went stale while the app sat open (the
// Mac slept, the session ran out): check in, which refreshes it, and send
// once more. `send` makes the request again from scratch.
async function withSessionRetry(method, url, send) {
    let response = await send();
    if (!sessionStarted()) return response;
    if (response.status === 419 && (await checkSession()) === 'ok') {
        response = await send();
    } else if (response.status === 401) {
        await checkSession();
    }
    noteFailure(method, url, response.status);
    return response;
}

async function parseResponse(response) {
    const isJson = response.headers.get('content-type')?.includes('application/json');
    const data = isJson ? await response.json() : null;

    if (!response.ok) {
        const signedOut = (response.status === 401 || response.status === 419) && isSignedOut();
        const error = new Error(signedOut ? SIGNED_OUT_MESSAGE : data?.message || failureMessage(response.status));
        error.status = response.status;
        error.errors = data?.errors || null;
        throw error;
    }

    return data;
}

async function request(method, url, body) {
    // Built on each try, so a retry carries the refreshed token.
    const send = () => {
        const headers = {
            Accept: 'application/json',
        };

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (method !== 'GET') {
            const token = readCookie('XSRF-TOKEN');
            if (token) {
                headers['X-XSRF-TOKEN'] = token;
            }
        }

        return fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    };

    return parseResponse(await withSessionRetry(method, url, send));
}

async function requestForm(url, formData) {
    const send = () => {
        const headers = { Accept: 'application/json' };
        const token = readCookie('XSRF-TOKEN');
        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }

        // No Content-Type header -- the browser sets multipart/form-data with
        // the correct boundary itself when the body is a FormData instance.
        return fetch(url, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: formData,
        });
    };

    return parseResponse(await withSessionRetry('POST', url, send));
}

// XMLHttpRequest, not fetch, is what gives us real upload-progress events
// (the message composer's per-attachment progress bar) -- fetch has no
// equivalent for a multipart request body.
function requestFormWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        const token = readCookie('XSRF-TOKEN');
        if (token) {
            xhr.setRequestHeader('X-XSRF-TOKEN', token);
        }

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable && onProgress) {
                onProgress(Math.round((e.loaded / e.total) * 100));
            }
        };

        xhr.onload = () => {
            const isJson = (xhr.getResponseHeader('content-type') || '').includes('application/json');
            const data = isJson && xhr.responseText ? JSON.parse(xhr.responseText) : null;

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(data);
                return;
            }

            if (sessionStarted()) {
                noteFailure('POST', url, xhr.status);
                if (xhr.status === 401 || xhr.status === 419) checkSession();
            }
            const error = new Error(data?.message || failureMessage(xhr.status));
            error.status = xhr.status;
            error.errors = data?.errors || null;
            reject(error);
        };

        xhr.onerror = () => reject(new Error('Network error'));

        xhr.send(formData);
    });
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body ?? {}),
    patch: (url, body) => request('PATCH', url, body ?? {}),
    put: (url, body) => request('PUT', url, body ?? {}),
    delete: (url, body) => request('DELETE', url, body),
    postForm: (url, formData) => requestForm(url, formData),
    postFormWithProgress: (url, formData, onProgress) => requestFormWithProgress(url, formData, onProgress),
};
