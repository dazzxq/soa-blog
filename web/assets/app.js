// Shared frontend logic — loaded as a classic script BEFORE Alpine CDN so
// `window.api / window.auth / window.navbar / window.formatDate` exist by the
// time Alpine evaluates the first x-data expression.
(function () {
  const API_BASE  = '/api';
  const TOKEN_KEY = 'soa_blog_token';
  const USER_KEY  = 'soa_blog_user';

  // Load Font Awesome 6 once (all icons across the app use FA — no emoji).
  if (!document.getElementById('fa-cdn')) {
    var faLink = document.createElement('link');
    faLink.id = 'fa-cdn';
    faLink.rel = 'stylesheet';
    faLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
    faLink.crossOrigin = 'anonymous';
    document.head.appendChild(faLink);
  }

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

  // Relative time, Vietnamese ("Vừa xong / N phút / N giờ / N ngày…"), LinkedIn-style.
  // DB stores UTC datetimes WITHOUT an offset ("2026-06-07 02:46:59") → treat as UTC
  // (append Z) so the relative value is correct regardless of the browser timezone.
  function timeAgo(iso) {
    if (!iso) return '';
    var s = String(iso).trim().replace(' ', 'T');
    if (!/[zZ]|[+\-]\d\d:?\d\d$/.test(s)) s += 'Z';
    var t = new Date(s);
    if (isNaN(t.getTime())) return formatDate(iso);
    var sec = Math.floor((Date.now() - t.getTime()) / 1000);
    if (sec < 45) return 'Vừa xong';
    var m = Math.round(sec / 60); if (m < 60) return m + ' phút';
    var h = Math.round(m / 60);   if (h < 24) return h + ' giờ';
    var d = Math.round(h / 24);   if (d < 7)  return d + ' ngày';
    var w = Math.round(d / 7);    if (w < 5)  return w + ' tuần';
    var mo = Math.round(d / 30);  if (mo < 12) return mo + ' tháng';
    return Math.round(d / 365) + ' năm';
  }

  // ---------- Basic rich text (FB/LinkedIn-style: bold/italic/underline/H2) ----
  // SAFE BY CONSTRUCTION: the input is HTML-escaped FIRST, then a tiny whitelist of
  // markers is turned into a FIXED set of tags (strong/em/u/h2/p/br). No user input
  // can ever inject markup, so binding the result with x-html is XSS-safe.
  // Storage syntax: **bold**  *italic*  __underline__  and a line starting "## " → H2.
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function renderRich(text) {
    if (text == null || text === '') return '';
    var fmt = function (s) {
      return s
        .replace(/\*\*([^\n]+?)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^\n]+?)__/g, '<u>$1</u>')
        .replace(/\*([^\n*]+?)\*/g, '<em>$1</em>');
    };
    var esc = escapeHtml(String(text));
    var html = '', para = [];
    var flush = function () { if (para.length) { html += '<p>' + para.join('<br>') + '</p>'; para = []; } };
    esc.split('\n').forEach(function (ln) {
      var h = ln.match(/^##\s+(.+)$/);
      if (h) { flush(); html += '<h2 class="text-lg font-semibold my-1">' + fmt(h[1]) + '</h2>'; }
      else if (ln.trim() === '') { flush(); }
      else { para.push(fmt(ln)); }
    });
    flush();
    return html;
  }

  // ---------- Expose globally for Alpine x-data="..." expressions ----------

  window.api        = api;
  window.auth       = auth;
  window.formatDate = formatDate;
  window.timeAgo    = timeAgo;
  window.escapeHtml = escapeHtml;
  window.renderRich = renderRich;

  // Render nội dung post theo cờ tin cậy do SERVER cấp (content_format):
  //   'html' = đã HTMLPurifier sanitize ở server → bind x-html trực tiếp;
  //   ngược lại (legacy 'md'/thiếu cờ) → renderRich (escape-first).
  // KHÔNG đoán an toàn bằng regex — trust boundary nằm ở server.
  function renderContent(post) {
    if (!post) return '';
    if (post.content_format === 'html') return post.content || '';
    return renderRich(post.content || '');
  }
  window.renderContent = renderContent;

  // ---------- Shared confirm dialog (glass) ----------
  // Vanilla Promise-based confirm so every page can `if (!await proConfirm(...)) return;`
  // before a destructive/irreversible action. NOT an Alpine component (avoids initTree
  // timing on a body-appended node). XSS-safe: all user-facing text via textContent.
  window.proConfirm = function (opts) {
    opts = opts || {};
    var title   = opts.title   || 'Xác nhận';
    var message = opts.message || '';
    var okText  = opts.okText  || (opts.danger ? 'Xoá' : 'Đồng ý');
    var cancel  = opts.cancelText || 'Huỷ';
    return new Promise(function (resolve) {
      var overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;inset:0;z-index:90;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(15,23,42,0.45)';
      var box = document.createElement('div');
      box.className = 'glass-strong';
      box.style.cssText = 'max-width:24rem;width:100%;padding:1.25rem;border-radius:1rem';
      var h = document.createElement('h3');
      h.style.cssText = 'font-weight:600;font-size:1rem;margin-bottom:.5rem';
      h.textContent = title;
      var p = document.createElement('p');
      p.style.cssText = 'font-size:.875rem;color:#475569;margin-bottom:1rem';
      p.textContent = message;
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;justify-content:flex-end;gap:.5rem';
      var bC = document.createElement('button');
      bC.className = 'pro-btn-ghost';
      bC.style.cssText = 'padding:.5rem 1rem;border-radius:.5rem;font-size:.875rem';
      bC.textContent = cancel;
      var bO = document.createElement('button');
      bO.style.cssText = 'padding:.5rem 1rem;border-radius:.5rem;font-size:.875rem;color:#fff;background:' + (opts.danger ? '#dc2626' : '#1e3a8a');
      bO.textContent = okText;
      row.appendChild(bC); row.appendChild(bO);
      box.appendChild(h); box.appendChild(p); box.appendChild(row);
      overlay.appendChild(box); document.body.appendChild(overlay);
      var done = function (val) {
        if (overlay.parentNode) document.body.removeChild(overlay);
        document.removeEventListener('keydown', onKey);
        resolve(val);
      };
      var onKey = function (e) { if (e.key === 'Escape') done(false); else if (e.key === 'Enter') done(true); };
      bC.addEventListener('click', function () { done(false); });
      bO.addEventListener('click', function () { done(true); });
      overlay.addEventListener('click', function (e) { if (e.target === overlay) done(false); });
      document.addEventListener('keydown', onKey);
      setTimeout(function () { bO.focus(); }, 0);
    });
  };

  // ---------- Shared post-card logic (feed + profile dùng chung) ----------
  // Spread vào component: return { ...postCardMixin(), <state/method riêng>, load() }.
  // Page-specific GHI ĐÈ mixin (đặt SAU). Mỗi trang PHẢI có error + load() riêng
  // (mixin gọi this.load để refresh sau thao tác). KHÔNG chứa compose/Trix.
  window.postCardMixin = function () {
    return {
      busy: false,
      comments: {},
      openComments: {},
      commentDraft: {},
      editCommentId: null,
      editCommentBody: '',
      reactions: ['like', 'love', 'haha', 'wow', 'sad', 'angry'],
      lightbox: { open: false, post: null, index: 0 },
      reactorsModal: { open: false, loading: false, items: [], total: 0, error: '' },

      reactionLabel(t) { return ({ like: 'Thích', love: 'Yêu thích', haha: 'Haha', wow: 'Wow', sad: 'Buồn', angry: 'Phẫn nộ' })[t] || t; },
      reactionIcon(t) { return ({ like: 'fa-thumbs-up', love: 'fa-heart', haha: 'fa-face-laugh-squint', wow: 'fa-face-surprise', sad: 'fa-face-sad-tear', angry: 'fa-face-angry' })[t] || 'fa-thumbs-up'; },
      reactionColor(t) { return ({ like: '#2563eb', love: '#e0245e', haha: '#f59e0b', wow: '#eab308', sad: '#f59e0b', angry: '#f97316' })[t] || '#2563eb'; },
      // Các loại cảm xúc có trên bài (tối đa 3 icon kiểu Facebook); fallback 'like' nếu có đếm mà thiếu chi tiết.
      topReactionTypes(p) {
        const t = (p && Array.isArray(p.reaction_types)) ? p.reaction_types.filter(Boolean) : [];
        if (t.length) return t.slice(0, 3);
        return (p && p.reaction_count > 0) ? ['like'] : [];
      },

      postImages(p) { if (p && Array.isArray(p.images) && p.images.length) return p.images; return (p && p.image_url) ? [p.image_url] : []; },
      gridClass(n) { return (n === 2 || n === 3 || n === 4) ? 'grid-cols-2' : 'grid-cols-3'; },
      cellClass(n, i) { if (n === 3 && i === 0) return 'col-span-2 aspect-[16/9]'; if (n === 2) return 'aspect-[4/3]'; return 'aspect-square'; },
      openLightbox(p, i) { this.lightbox = { open: true, post: p, index: i }; this._scrollLock(); this.loadComments(p.id); },
      closeLightbox() { this.lightbox.open = false; this._scrollLock(); },
      lbImages() { return this.lightbox.post ? this.postImages(this.lightbox.post) : []; },
      lbPrev() { const n = this.lbImages().length; if (n) this.lightbox.index = (this.lightbox.index - 1 + n) % n; },
      lbNext() { const n = this.lbImages().length; if (n) this.lightbox.index = (this.lightbox.index + 1) % n; },

      react(id, type) { return this._act(() => api.post('/posts/' + id + '/reactions', { type })); },
      unreact(id) { return this._act(() => api.delete('/posts/' + id + '/reactions')); },
      repost(id) { return this._act(() => api.post('/posts/' + id + '/repost')); },
      async removePost(id) {
        if (!await proConfirm({ title: 'Xoá bài viết', message: 'Xoá bài viết này? Hành động không thể hoàn tác.', danger: true })) return;
        return this._act(() => api.delete('/posts/' + id));
      },

      async loadComments(id) {
        try { const r = await api.get('/posts/' + id + '/comments'); this.comments[id] = r.data || []; this.openComments[id] = true; }
        catch (e) { this.error = 'Không tải được bình luận: ' + e.message; }
      },
      async addComment(id) {
        const b = (this.commentDraft[id] || '').trim();
        if (!b || this.busy) return;
        this.busy = true; this.error = '';
        try { await api.post('/posts/' + id + '/comments', { body: b }); this.commentDraft[id] = ''; await this.loadComments(id); await this.load(); this._resyncLightbox(); }
        catch (e) { this.error = 'Gửi bình luận không thành công: ' + e.message; }
        finally { this.busy = false; }
      },
      startEditComment(c) { this.editCommentId = c.id; this.editCommentBody = c.body || ''; },
      async saveComment(pid, cid) {
        const b = (this.editCommentBody || '').trim();
        if (!b || this.busy) return;
        this.busy = true; this.error = '';
        try { await api.patch('/comments/' + cid, { body: b }); this.editCommentId = null; await this.loadComments(pid); }
        catch (e) { this.error = 'Sửa bình luận không thành công: ' + e.message; }
        finally { this.busy = false; }
      },
      async deleteComment(pid, cid) {
        if (this.busy) return;
        if (!await proConfirm({ title: 'Xoá bình luận', message: 'Xoá bình luận này?', danger: true })) return;
        this.busy = true; this.error = '';
        try { await api.delete('/comments/' + cid); await this.loadComments(pid); await this.load(); this._resyncLightbox(); }
        catch (e) { this.error = 'Xoá bình luận không thành công: ' + e.message; }
        finally { this.busy = false; }
      },

      // Ai đã react — modal (lấy 100 đầu; nếu total lớn hơn thì hiện "và N người khác").
      async openReactors(id) {
        this.reactorsModal = { open: true, loading: true, items: [], total: 0, error: '' };
        this._scrollLock();
        try {
          const r = await api.get('/posts/' + id + '/reactions?per_page=100');
          this.reactorsModal.items = r.data || [];
          this.reactorsModal.total = (r.meta && r.meta.total) || (r.data || []).length;
        } catch (e) { this.reactorsModal.error = 'Không tải được danh sách cảm xúc.'; }
        finally { this.reactorsModal.loading = false; }
      },
      closeReactors() { this.reactorsModal.open = false; this._scrollLock(); },

      // Khoá scroll nền khi có lightbox/modal mở; mở lại khi đóng hết.
      _scrollLock() { document.body.style.overflow = (this.lightbox.open || this.reactorsModal.open) ? 'hidden' : ''; },
      // Sau khi load() thay this.posts, trỏ lightbox.post về bài MỚI cùng id (để react/comment trong lightbox cập nhật đúng).
      _resyncLightbox() {
        if (!this.lightbox.open || !this.lightbox.post) return;
        const id = String(this.lightbox.post.id);
        const f = (this.posts || []).find(x => String(x.id) === id);
        if (f) this.lightbox.post = f;
      },

      async _act(fn) {
        if (this.busy) return;
        this.busy = true; this.error = '';
        try { await fn(); await this.load(); this._resyncLightbox(); }
        catch (e) { this.error = 'Thao tác không thành công: ' + e.message; }
        finally { this.busy = false; }
      },
    };
  };

  // Post-card / lightbox / modal "ai đã react": markup dùng chung sống ở
  // web/partials/*.html, ghép phía máy chủ bằng nginx SSI (<!--# include -->).
  // KHÔNG inject bằng JS nữa (tránh đua thời điểm với Alpine x-for).

  // Danh sách chức danh phổ biến (dùng chung cho autocomplete headline + experience).
  window.COMMON_TITLES = [
    'Sinh viên', 'Thực tập sinh', 'Kỹ sư phần mềm', 'Lập trình viên', 'Lập trình viên Frontend',
    'Lập trình viên Backend', 'Lập trình viên Full-stack', 'Kỹ sư DevOps', 'Kỹ sư dữ liệu',
    'Nhà khoa học dữ liệu', 'Kỹ sư AI/Machine Learning', 'Kỹ sư QA/Kiểm thử', 'Quản trị hệ thống',
    'Kỹ sư bảo mật', 'Chuyên viên phân tích nghiệp vụ', 'Quản lý dự án', 'Product Manager',
    'Product Owner', 'Scrum Master', 'Thiết kế UI/UX', 'Thiết kế đồ hoạ', 'Kiến trúc sư phần mềm',
    'Trưởng nhóm kỹ thuật', 'Giám đốc công nghệ (CTO)', 'Chuyên viên Marketing', 'Chuyên viên nhân sự',
    'Kế toán', 'Chuyên viên kinh doanh', 'Giảng viên', 'Nghiên cứu sinh',
  ];

  // (Autocomplete chức danh dùng x-data inline bind thẳng vào model cha — xem
  // profile-edit.html — nên không cần factory ở đây; chỉ cần window.COMMON_TITLES.)

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

  // ---------- Profile composition loader (Phase 2) ----------
  // Fetches the flagship aggregate GET /api/profiles/{id}/full and surfaces the
  // degraded flag. Used by profile.html / profile-edit.html.
  window.loadFull = async function (id) {
    const r = await api.get('/profiles/' + id + '/full');
    return { profile: r.data, degraded: !!(r.meta && r.meta.degraded) };
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

  // ---------- Notification bell (Phase 5, D-06/D-10) ----------
  // Navbar dropdown declared as <div x-data="notificationBell()" x-init="start()">.
  // Polls GET /api/notifications every ~15s for the unread badge; mark-one /
  // mark-all-read via POST. Defined ONCE here so all pages reuse it (no inline
  // duplication). Renders ONLY via x-text (XSS-safe, T-05-21).
  window.notificationBell = function () {
    return {
      open: false,
      items: [],
      unread: 0,
      timer: null,
      async load() {
        if (!auth.isLoggedIn()) return;
        try {
          const r = await api.get('/notifications');
          this.items = r.data || [];
          this.unread = (r.meta && r.meta.unread_count) || 0;
        } catch (e) { /* degrade silently — keep last badge */ }
      },
      start() {
        this.load();
        this.timer = setInterval(() => this.load(), 15000); // ~15s (D-06)
      },
      async markOne(id) {
        try { await api.post('/notifications/' + id + '/read'); } catch (e) { /* swallow */ }
        this.load();
      },
      async markAll() {
        try { await api.post('/notifications/read-all'); } catch (e) { /* swallow */ }
        this.load();
      },
      // Click a notification → mark read, then deep-link to its target.
      // reaction/comment: ref_id is the post id → /feed.html?post=<id>. invite → connections.
      async openNotif(n) {
        await this.markOne(n.id);
        // Giữ ID dạng chuỗi số (BIGINT-safe, không để Number() làm tròn ID 64-bit).
        var rid = String(n.ref_id == null ? '' : n.ref_id);
        if ((n.type === 'reaction' || n.type === 'comment') && /^[1-9]\d*$/.test(rid)) {
          window.location.href = '/post/' + encodeURIComponent(rid);
        } else if (n.type === 'invite') {
          window.location.href = '/connections';
        }
      },
      message(n) {
        const who = (n.actor && n.actor.display_name) || 'Ai đó';
        if (n.type === 'invite')   return who + ' đã gửi cho bạn lời mời kết nối';
        if (n.type === 'reaction') return who + ' đã bày tỏ cảm xúc về bài viết của bạn';
        if (n.type === 'comment')  return who + ' đã bình luận về bài viết của bạn';
        return who + ' có hoạt động mới';
      },
    };
  };

  // ===========================================================================
  // Shared ProConnect navbar (Phase 6, D-02 DRY / UI-01 / UI-04).
  //
  // ONE navbar for all 8 pages. Each page drops a `<div id="pronav"></div>`
  // placeholder and calls `proNav(active)`. No build, no templating, no markup
  // duplication. SECURITY (T-06-01/02): PRONAV_HTML below is a STATIC dev-authored
  // string — it interpolates NO user data and NO token. Every user value
  // (display_name, notif message) renders via Alpine `x-text` only — never the
  // unsafe HTML-binding directive.
  //
  // Alpine 3 init model (T-06-19): Alpine walks the DOM ONCE at init. An x-data
  // node attached AFTER that walk gets NO reactivity unless we re-scan it. So
  // proNav() calls `Alpine.initTree(el)` when Alpine is ready — making the navbar
  // reactive whether proNav runs BEFORE Alpine init (Alpine's own walk picks it
  // up; initTree absent → skipped safely) or AFTER (we initialise the subtree).
  // ===========================================================================

  // Chain-link logo (two interlocking navy rings) — self-designed inline SVG, no
  // third-party brand assets (T-06-04). Brand text: "ProConnect".
  var PRONAV_HTML =
    '<nav class="glass-nav z-30">' +
      '<div class="max-w-[1248px] mx-auto px-4 py-2 flex items-center justify-between gap-4">' +
        // LEFT: logo + brand + tagline
        '<a href="/feed" class="flex items-center gap-2 shrink-0">' +
          '<svg viewBox="0 0 24 24" class="w-7 h-7" fill="none" stroke="#1e3a8a" stroke-width="2" ' +
               'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<rect x="2" y="8" width="13" height="8" rx="4"></rect>' +
            '<rect x="9" y="8" width="13" height="8" rx="4"></rect>' +
          '</svg>' +
          '<span class="font-bold text-lg" style="color:#1e3a8a">ProConnect</span>' +
        '</a>' +
        // MIDDLE: search (logged-in only) → /search.html?q=
        '<template x-if="isLoggedIn">' +
          '<form @submit.prevent="location.href=\'/search?q=\'+encodeURIComponent($refs.nq.value)" class="hidden sm:block flex-1 max-w-xs">' +
            '<input x-ref="nq" type="search" placeholder="Tìm người, kỹ năng…" ' +
                   'class="glass-input px-3 py-1.5 text-sm w-full" />' +
          '</form>' +
        '</template>' +
        // RIGHT (logged-in): nav links + invite badge + bell + profile menu
        '<template x-if="isLoggedIn">' +
          '<div class="flex items-center gap-3 sm:gap-4 text-sm shrink-0 min-w-0">' +
            // "Bảng tin" text link hidden on xs (logo already links to feed) to avoid
            // navbar overflow on small screens (codex impl-review fix).
            '<a href="/feed" class="hidden sm:flex flex-col items-center leading-tight gap-0.5" :class="active===\'feed\' ? \'text-navy\' : \'subtle hover:text-slate-700\'">' +
              '<i class="fa-solid fa-house text-base" aria-hidden="true"></i><span class="text-xs">Bảng tin</span>' +
            '</a>' +
            '<a href="/connections" class="relative flex flex-col items-center leading-tight gap-0.5" :class="active===\'connections\' ? \'text-navy\' : \'subtle hover:text-slate-700\'">' +
              '<i class="fa-solid fa-user-group text-base" aria-hidden="true"></i><span class="text-xs">Kết nối</span>' +
              '<span x-show="invites>0" x-cloak ' +
                    'class="pro-badge absolute -top-1 right-1 text-[10px] rounded-full min-w-[16px] h-[16px] px-1 inline-flex items-center justify-center" ' +
                    'x-text="invites"></span>' +
            '</a>' +
            // Notification bell — reuse Phase-5 window.notificationBell (initTree
            // initialises this nested x-data too). Markup mirrors feed.html.
            '<div x-data="notificationBell()" x-init="start()" class="relative">' +
              '<button @click="open = !open" title="Thông báo" ' +
                      'class="relative flex flex-col items-center leading-tight gap-0.5 subtle hover:text-slate-700">' +
                '<i class="fa-regular fa-bell text-base" aria-hidden="true"></i><span class="text-xs">Thông báo</span>' +
                '<span x-show="unread > 0" x-cloak ' +
                      'class="pro-badge absolute -top-1 right-2 text-[10px] rounded-full min-w-[16px] h-[16px] px-1 inline-flex items-center justify-center" ' +
                      'x-text="unread"></span>' +
              '</button>' +
              '<div x-show="open" @click.outside="open = false" x-cloak ' +
                   'class="absolute right-0 mt-2 w-80 glass-strong rounded-2xl z-40 text-left overflow-hidden">' +
                '<div class="flex items-center justify-between px-3 py-2 border-b border-slate-200/60">' +
                  '<span class="font-semibold text-sm">Thông báo</span>' +
                  '<button @click="markAll()" class="text-xs text-navy hover:underline">Đánh dấu tất cả đã đọc</button>' +
                '</div>' +
                '<template x-if="!items.length">' +
                  '<p class="subtle text-sm px-3 py-4">Chưa có thông báo nào.</p>' +
                '</template>' +
                '<ul class="max-h-80 overflow-y-auto divide-y">' +
                  '<template x-for="n in items" :key="n.id">' +
                    '<li @click="openNotif(n)" ' +
                        ':class="n.read_at ? \'hover:bg-slate-50\' : \'pro-surface hover:bg-slate-100 font-semibold\'" ' +
                        'class="px-3 py-2 cursor-pointer">' +
                      '<p class="text-sm" x-text="message(n)"></p>' +
                      '<p class="subtle text-xs mt-0.5" x-text="timeAgo(n.created_at)"></p>' +
                    '</li>' +
                  '</template>' +
                '</ul>' +
              '</div>' +
            '</div>' +
            // Profile menu — avatar/name → dropdown (Hồ sơ / Chỉnh sửa / Đăng xuất)
            '<div class="relative" x-data="{m:false}">' +
              '<button @click="m=!m" class="flex items-center gap-2 hover:text-slate-700 min-w-0">' +
                // avatar + truncated name (truncate guards long Vietnamese names on small screens)
                '<span class="w-8 h-8 rounded-full bg-navy/10 text-navy overflow-hidden flex items-center justify-center text-xs font-bold shrink-0">' +
                  '<template x-if="me.avatar_url"><img :src="me.avatar_url" alt="" class="w-full h-full object-cover" /></template>' +
                  '<template x-if="!me.avatar_url"><span x-text="(me.display_name||me.username||\'?\').trim().charAt(0).toUpperCase()"></span></template>' +
                '</span>' +
                '<span class="hidden sm:inline max-w-[120px] truncate text-sm" x-text="me.display_name || me.username"></span>' +
                '<i class="fa-solid fa-chevron-down text-xs shrink-0 subtle" aria-hidden="true"></i>' +
              '</button>' +
              '<div x-show="m" @click.outside="m=false" x-cloak ' +
                   'class="absolute right-0 mt-2 w-44 glass-strong rounded-2xl z-40 text-left overflow-hidden">' +
                '<a :href="\'/profile/\'+me.id" class="block px-3 py-2 hover:bg-slate-50">Hồ sơ của tôi</a>' +
                '<a href="/profile-edit" class="block px-3 py-2 hover:bg-slate-50">Chỉnh sửa hồ sơ</a>' +
                '<button @click="logout()" class="block w-full text-left px-3 py-2 text-red-600 hover:bg-slate-50">Đăng xuất</button>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</template>' +
        // RIGHT (logged-out): Đăng nhập / Đăng ký
        '<template x-if="!isLoggedIn">' +
          '<div class="flex items-center gap-3 text-sm shrink-0">' +
            '<a href="/login" class="hover:underline">Đăng nhập</a>' +
            '<a href="/register" class="pro-btn px-3 py-1.5 rounded">Đăng ký</a>' +
          '</div>' +
        '</template>' +
      '</div>' +
    '</nav>';

  // Alpine component backing #pronav: login state, current user, invite badge poll.
  window.proNavData = function (active) {
    return {
      active: active || '',
      isLoggedIn: auth.isLoggedIn(),
      me: auth.user() || {},
      invites: 0,
      timer: null,
      init() {
        if (!this.isLoggedIn) return;
        this.refreshMe();   // localStorage user có thể cũ (đổi avatar/tên) → lấy /me tươi
        this.loadInvites();
        this.timer = setInterval(() => this.loadInvites(), 15000); // D-06 ~15s
      },
      // Cập nhật avatar/tên header từ /me (vì auth.user() trong localStorage chỉ set lúc đăng nhập).
      async refreshMe() {
        try {
          const m = (await api.get('/me')).data;
          if (m) { this.me = m; auth.save(m, auth.token()); } // đồng bộ lại localStorage
        } catch (e) { /* giữ localStorage nếu lỗi/timeout */ }
      },
      async loadInvites() {
        try {
          // D-06: incoming pending connection requests → invite badge count.
          const r = await api.get('/connections/requests?direction=incoming');
          this.invites = (r.data || []).length;
        } catch (e) { /* degrade: keep last badge */ }
      },
      logout() { auth.logout(); window.location.href = '/'; }, // really clears token then redirects
    };
  };

  // Inject the shared navbar into <div id="pronav"> and make it reactive.
  // active: 'feed' | 'connections' | 'search' | 'profile' | '' (highlights link).
  window.proNav = function (active) {
    var el = document.getElementById('pronav');
    if (!el) return;
    el.setAttribute('x-data', 'proNavData("' + (active || '') + '")');
    el.setAttribute('x-init', 'init()');
    el.innerHTML = PRONAV_HTML; // STATIC markup — no user data interpolated (T-06-01/02)
    // Make the #pronav wrapper itself the sticky element (it has NO backdrop-filter).
    // Putting position:sticky on the glass <nav> directly mis-behaves in some browsers
    // (sticky + backdrop-filter on the same element), so the filtered nav stays static
    // inside this sticky, unfiltered wrapper. Inline styles avoid CDN class-purge gaps.
    el.style.position = 'sticky';
    el.style.top = '0';
    el.style.zIndex = '40';
    // Reactivity contract (T-06-19): set-attribute + innerHTML alone does NOT make
    // an after-init subtree reactive. Alpine.initTree(el) does — safe in BOTH cases:
    //   - proNav AFTER Alpine init → we initialise this subtree here.
    //   - proNav BEFORE Alpine init → initTree is undefined, skipped; Alpine's own
    //     walk later picks up the x-data we just set.
    if (window.Alpine && typeof Alpine.initTree === 'function') {
      Alpine.initTree(el);
    }
  };
})();
