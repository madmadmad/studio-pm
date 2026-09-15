function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
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

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body ?? {}),
    patch: (url, body) => request('PATCH', url, body ?? {}),
    put: (url, body) => request('PUT', url, body ?? {}),
    delete: (url) => request('DELETE', url),
};
