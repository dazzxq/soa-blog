# ProConnect — Liquid Glass restyle spec (for page agents)

Apply Apple-Liquid-Glass-inspired styling to the existing pages. **VISUAL ONLY** — do
NOT change any Alpine logic, fetch calls, endpoints, or x-model/x-for/@click bindings.
No backend changes. Keep everything working.

## Design system (already in web/assets/styles.css — just USE the classes)
- **Background**: `body` already has a static mesh gradient. Do NOT set a bg color on
  the page body; let the mesh show. Remove old `bg-slate-50`/`bg-gray-100` from `<body>`.
- **Glass classes (use, don't redefine):**
  - `.glass` — top-level CARD material (translucent, blur, specular edge, soft shadow, radius). Use for the main cards/panels (profile card, post card, compose box, suggestion panel, auth card, search-result card).
  - `.glass-strong` — more opaque glass for text-dense panels (forms, dropdowns).
  - `.glass-hover` — add to clickable cards for a lift on hover.
  - `.surface-soft` / `.surface-tint` — NESTED items INSIDE a glass card (avoid glass-on-glass per Apple HIG). Use for list rows, nested boxes, reposts, comment rows.
  - `.glass-input` — text inputs / textareas / selects.
  - `.pro-btn` — primary action button (glossy navy). `.pro-btn-ghost` — secondary/ghost glass button.
  - `.pro-badge` — count badges. `.text-navy` — navy text. `.subtle` — muted text.
- **Rules (Apple HIG):** glass on the navigation + top-level card layer ONLY; nested elements use `.surface-soft`/plain. Don't stack glass on glass. Keep text dark (slate-800/900) for contrast. Generous rounded corners (rounded-2xl), soft spacing.

## What to change per page
1. **`<body>`**: drop opaque bg utility classes (e.g. `bg-slate-50`, `bg-gray-50`, `class="bg-..."`). Keep layout/spacing. Optionally add `fade-in` to the main container.
2. **Cards/panels**: replace `bg-white border rounded-lg shadow` (and similar) with `glass` (or `glass-strong` for forms) + keep padding. Add `glass-hover` to clickable cards (post cards, suggestion/search rows that link to a profile).
3. **Nested boxes** inside a glass card (repost preview, comment list rows, stat tiles): use `.surface-soft` instead of another `bg-white`/`border` so glass isn't stacked.
4. **Buttons**: primary → `.pro-btn`; secondary/cancel → `.pro-btn-ghost`. Keep existing classes for padding/rounded (e.g. `px-4 py-2 rounded-lg`).
5. **Inputs/textarea/select**: add `.glass-input` (replace plain `border rounded` input styling).
6. **Avatars now have real images**: `avatar_url` is populated for seeded users (and `cover_url` exists). Keep existing avatar `<img :src>` logic. **profile.html**: if there's a header/banner area, render `cover_url` as a cover image banner (object-cover, rounded-t-2xl) above the avatar when present — nice touch, but only if the page already loads the full profile (it does via /full). Fall back gracefully when null.
7. **Cache-bust**: bump BOTH `styles.css?v=ph6-01` → `styles.css?v=glass-1` AND `app.js?v=ph6-01` → `app.js?v=glass-1` on every page you touch (full-token replace; assert no `ph6-01` remains).
8. Keep the shared navbar (`<div id="pronav">` + proNav) exactly as is — it's already glass via app.js.
9. Keep `lang="vi"`, all Vietnamese UI text, x-text (NOT x-html), inline `tailwind.config` navy block.

## Verify (static, grep)
- `grep -c glass <page>` > 0; primary buttons use `pro-btn`; inputs use `glass-input`;
- no `ph6-01` token remains; `glass-1` present on both assets;
- no `x-html`; no backend files touched; HTML tags balanced; no `bg-white` left on main cards (ok inside dropdowns).
