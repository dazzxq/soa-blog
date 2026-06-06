// Shared frontend logic — loaded as a classic script BEFORE Alpine CDN so
// `window.api / window.auth / window.navbar / window.formatDate` exist by the
// time Alpine evaluates the first x-data expression.
(function () {
  const API_BASE  = '/api';
  const TOKEN_KEY = 'soa_blog_token';
  const USER_KEY  = 'soa_blog_user';

  // ---------- Auth helpers ----------

  const auth = {
    token() { return localStorage.getItem(TOKEN_KEY) || ''; },
    user()  {
      const raw = localStorage.getItem(USER_KEY);
      try { return raw ? JSON.parse(raw) : null; } catch (_) { return null; }
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
    const token   = auth.token();
    if (token) headers['Authorization'] = 'Bearer ' + token;

    const init = { method: method, headers: headers, credentials: 'same-origin' };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }

    const res = await fetch(API_BASE + path, init);

    if (res.status === 204) return { ok: true, status: 204, data: null };

    let payload = null;
    try { payload = await res.json(); } catch (_) { /* non-JSON body */ }

    if (!res.ok) {
      const err = new Error((payload && payload.error && payload.error.message) || ('HTTP ' + res.status));
      err.status  = res.status;
      err.code    = (payload && payload.error && payload.error.code) || 'HTTP_ERROR';
      err.details = payload;
      throw err;
    }

    return {
      ok: true,
      status: res.status,
      data: payload && payload.data !== undefined ? payload.data : payload,
      meta: payload && payload.meta,
    };
  }

  const api = {
    get   : function (p)        { return request('GET',    p); },
    post  : function (p, body)  { return request('POST',   p, body); },
    patch : function (p, body)  { return request('PATCH',  p, body); },
    delete: function (p)        { return request('DELETE', p); },
  };

  // ---------- Date utility (Vietnamese) ----------

  function formatDate(iso) {
    if (!iso) return '';
    try {
      return new Date(iso).toLocaleString('vi-VN', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit',
      });
    } catch (_) { return iso; }
  }

  // ---------- Expose globally for Alpine x-data="..." expressions ----------

  window.api        = api;
  window.auth       = auth;
  window.formatDate = formatDate;

  // ---------- Profile loader (Phase-1 endpoints only) ----------
  // Uses /api/me (JWT) to resolve the current account, then /api/profiles/{id}
  // for the public basic profile {id, username, display_name}. The retired blog
  // post/comment endpoints are intentionally NOT called here (removed in Phase 1).
  window.loadProfile = async function () {
    if (!auth.isLoggedIn()) return null;
    const me = await api.get('/me');                 // { data: { id, username, display_name, ... } }
    const id = me.data && me.data.id;
    if (!id) return me.data || null;
    const prof = await api.get('/profiles/' + id);   // { data: { id, username, display_name } }
    return prof.data || me.data || null;
  };

  // Top-bar binding — pages declare <nav x-data="navbar()">
  window.navbar = function () {
    return {
      user: auth.user(),
      isLoggedIn: auth.isLoggedIn(),
      logout() {
        auth.logout();
        window.location.href = '/';
      },
    };
  };
})();
