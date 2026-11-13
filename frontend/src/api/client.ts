// Fetch wrapper: adds the JWT and X-Company-Id, refreshes the access token once on 401,
// and turns the API's {status, data | error} envelope into data or an ApiError.

export interface FieldErrors {
  [field: string]: string[];
}

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public code: string,
    public fields: FieldErrors = {},
  ) {
    super(message);
  }
}

export interface Paged<T> {
  items: T[];
  total: number;
  page: number;
  per_page: number;
}

const COMPANY_KEY = 'rb.companyId';

let accessToken: string | null = null;
let refreshing: Promise<boolean> | null = null;
let onLoggedOut: () => void = () => {};

export const session = {
  setToken(token: string | null) {
    accessToken = token;
  },
  hasToken() {
    return accessToken !== null;
  },
  companyId(): number | null {
    const v = localStorage.getItem(COMPANY_KEY);
    return v ? Number(v) : null;
  },
  setCompanyId(id: number | null) {
    if (id === null) localStorage.removeItem(COMPANY_KEY);
    else localStorage.setItem(COMPANY_KEY, String(id));
  },
  onLoggedOut(fn: () => void) {
    onLoggedOut = fn;
  },
};

export async function refreshToken(): Promise<boolean> {
  refreshing ??= (async () => {
    try {
      const res = await fetch('/api/v1/auth/refresh', { method: 'POST', credentials: 'same-origin' });
      if (!res.ok) return false;
      const body = await res.json();
      accessToken = body.data.access_token;
      return true;
    } catch {
      return false;
    } finally {
      setTimeout(() => (refreshing = null), 0);
    }
  })();
  return refreshing;
}

type Query = Record<string, string | number | boolean | null | undefined>;

export function withQuery(path: string, query?: Query): string {
  if (!query) return path;
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
  }
  const qs = params.toString();
  return qs ? `${path}${path.includes('?') ? '&' : '?'}${qs}` : path;
}

async function raw(method: string, path: string, body?: unknown, retry = true): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
  const companyId = session.companyId();
  if (companyId) headers['X-Company-Id'] = String(companyId);

  let payload: BodyInit | undefined;
  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  const res = await fetch(`/api/v1${path}`, { method, headers, body: payload, credentials: 'same-origin' });
  if (res.status === 401 && retry && !path.startsWith('/auth/')) {
    if (await refreshToken()) return raw(method, path, body, false);
    accessToken = null;
    onLoggedOut();
  }
  return res;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await raw(method, path, body);
  let json: any = null;
  try {
    json = await res.json();
  } catch {
    // non-JSON error page
  }
  if (!res.ok || json?.status !== 'success') {
    const err = json?.error ?? {};
    throw new ApiError(err.message ?? `Request failed (${res.status})`, res.status, err.code ?? 'error', err.fields ?? {});
  }
  return json.data as T;
}

export const api = {
  get: <T>(path: string, query?: Query) => request<T>('GET', withQuery(path, query)),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body ?? {}),
  del: <T>(path: string) => request<T>('DELETE', path),
  /** Authenticated binary download (PDF, XLSX). */
  async blob(path: string, query?: Query): Promise<Blob> {
    const res = await raw('GET', withQuery(path, query));
    if (!res.ok) {
      let message = `Download failed (${res.status})`;
      try {
        message = (await res.json()).error?.message ?? message;
      } catch {
        // ignore
      }
      throw new ApiError(message, res.status, 'download_failed');
    }
    return res.blob();
  },
};
