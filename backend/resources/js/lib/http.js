// Shared CSRF + JSON fetch helpers for same-origin admin actions.

function cookieValue(name) {
    const prefix = `${name}=`;
    const match = (document.cookie || '')
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(prefix));

    return match ? decodeURIComponent(match.slice(prefix.length)) : '';
}

export function csrfHeaders() {
    // Laravel refreshes XSRF-TOKEN with the session. Inertia keeps the root
    // document alive across navigation, so its original meta token can become
    // stale while the cookie remains current.
    const cookieToken = cookieValue('XSRF-TOKEN');
    if (cookieToken) return { 'X-XSRF-TOKEN': cookieToken };

    const metaToken = document.querySelector('meta[name=csrf-token]')?.content ?? '';
    return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
}

// JSON fetch with the CSRF + XHR headers Laravel expects for stateful requests.
export async function apiCall(url, method, body) {
    return fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

// Read-only JSON GET (no CSRF needed); pairs with apiCall for writes.
export async function apiGet(url) {
    return fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
}
