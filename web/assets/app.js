// Shared frontend logic — used by every page via <script src="/assets/app.js">.
// Exposes window.api, window.auth, and a few helpers for Alpine components.

const API_BASE = '/api';
const TOKEN_KEY = 'soa_blog_token';
const USER_KEY  = 'soa_blog_user';

// ---------- Auth helpers ----------

export const auth = {
  token() { return localStorage.getItem(TOKEN_KEY) || ''; },
  user()  {
    const raw = localStorage.getItem(USER_KEY);
    try { return raw ? JSON.parse(raw) : null; } catch { return null; }
  },
  isLoggedIn() { return !!this.token(); },
  save(user, token) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },
  logout() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  },
};

// ---------- HTTP wrapper ----------

async function request(method, path, body) {
  const headers = { 'Accept': 'application/json' };
  const token = auth.token();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const init = { method, headers, credentials: 'same-origin' };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  const res = await fetch(API_BASE + path, init);

  // 204 No Content
  if (res.status === 204) return { ok: true, status: 204, data: null };

  let payload = null;
  try { payload = await res.json(); } catch { /* non-JSON body */ }

  if (!res.ok) {
    const err = new Error((payload && payload.error && payload.error.message) || `HTTP ${res.status}`);
    err.status  = res.status;
    err.code    = payload?.error?.code || 'HTTP_ERROR';
    err.details = payload;
    throw err;
  }

  return { ok: true, status: res.status, data: payload?.data ?? payload, meta: payload?.meta };
}

export const api = {
  get   : (p)        => request('GET',    p),
  post  : (p, body)  => request('POST',   p, body),
  patch : (p, body)  => request('PATCH',  p, body),
  delete: (p)        => request('DELETE', p),
};

// ---------- Date utility (Vietnamese) ----------

export function formatDate(iso) {
  if (!iso) return '';
  try {
    const d = new Date(iso);
    return d.toLocaleString('vi-VN', {
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit',
    });
  } catch { return iso; }
}

// ---------- Expose globally (Alpine reads from window) ----------

window.api        = api;
window.auth       = auth;
window.formatDate = formatDate;

// Top-bar binding — most pages have <div x-data="navbar()">
window.navbar = () => ({
  user: auth.user(),
  isLoggedIn: auth.isLoggedIn(),
  logout() {
    auth.logout();
    window.location.href = '/';
  },
});
