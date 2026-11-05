import type {
  ApiError,
  StageTransition,
  TransitionRequest,
  User,
  VendorListResponse,
  VendorView,
} from '../../shared/types';

const TOKEN_KEY = 'vendor-tracker.token';

export class ApiRequestError extends Error {
  constructor(
    readonly status: number,
    readonly body: ApiError,
  ) {
    super(body.error);
  }
}

let onUnauthorized: () => void = () => {};

/** Lets the app return to the login screen when a session expires mid-use. */
export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler;
}

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    // Storage unavailable (private mode): the session just won't survive a reload.
  }
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const token = getToken();
  const res = await fetch(`/api${path}`, {
    method,
    headers: {
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  if (res.status === 204) return undefined as T;
  const data = await res.json().catch(() => ({ error: `Request failed (${res.status}).` }));

  if (!res.ok) {
    if (res.status === 401 && path !== '/auth/login') {
      setToken(null);
      onUnauthorized();
    }
    throw new ApiRequestError(res.status, data as ApiError);
  }
  return data as T;
}

export const api = {
  async login(username: string, password: string): Promise<User> {
    const { token, user } = await request<{ token: string; user: User }>('POST', '/auth/login', {
      username,
      password,
    });
    setToken(token);
    return user;
  },

  async logout(): Promise<void> {
    try {
      await request<void>('POST', '/auth/logout');
    } finally {
      setToken(null);
    }
  },

  me: () => request<{ user: User }>('GET', '/auth/me').then((r) => r.user),

  listVendors: () => request<VendorListResponse>('GET', '/vendors'),

  history: (vendorId: number) =>
    request<{ history: StageTransition[] }>('GET', `/vendors/${vendorId}/history`).then((r) => r.history),

  transition: (vendorId: number, body: TransitionRequest) =>
    request<{ vendor: VendorView; transition: StageTransition }>(
      'POST',
      `/vendors/${vendorId}/transitions`,
      body,
    ),
};
