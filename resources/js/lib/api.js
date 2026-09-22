function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
}

async function parseResponse(response) {
    const isJson = response.headers.get('content-type')?.includes('application/json');
    const data = isJson ? await response.json() : null;

    if (!response.ok) {
        const error = new Error(data?.message || `Request failed (${response.status})`);
        error.status = response.status;
        error.errors = data?.errors || null;
        throw error;
    }

    return data;
}

async function request(method, url, body) {
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

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    return parseResponse(response);
}

async function requestForm(url, formData) {
    const headers = { Accept: 'application/json' };
    const token = readCookie('XSRF-TOKEN');
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    // No Content-Type header -- the browser sets multipart/form-data with
    // the correct boundary itself when the body is a FormData instance.
    const response = await fetch(url, {
        method: 'POST',
        headers,
        credentials: 'same-origin',
        body: formData,
    });

    return parseResponse(response);
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

            const error = new Error(data?.message || `Request failed (${xhr.status})`);
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
    delete: (url) => request('DELETE', url),
    postForm: (url, formData) => requestForm(url, formData),
    postFormWithProgress: (url, formData, onProgress) => requestFormWithProgress(url, formData, onProgress),
};
