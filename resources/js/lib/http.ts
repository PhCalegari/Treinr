// fetch com o token CSRF do Laravel (cookie XSRF-TOKEN), para chamadas JSON fora do Inertia.

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        public status: number,
        public body: unknown,
    ) {
        super(`HTTP ${status}`);
    }
}

export async function getJson<T>(url: string): Promise<T> {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const body = await res.json().catch(() => null);
    if (!res.ok) throw new HttpError(res.status, body);
    return body as T;
}

export async function postJson<T>(url: string, data: unknown): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(data),
    });
    const body = await res.json().catch(() => null);
    if (!res.ok) throw new HttpError(res.status, body);
    return body as T;
}

/** Envio de arquivo (multipart) com resposta JSON. */
export async function postForm<T>(url: string, data: FormData): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: data,
    });
    const body = await res.json().catch(() => null);
    if (!res.ok) throw new HttpError(res.status, body);
    return body as T;
}
