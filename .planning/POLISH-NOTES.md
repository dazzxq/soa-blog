# UI polish pass — shared conventions (from LinkedIn-informed audit)

Apply with Tailwind utilities + the existing styles.css helpers. VISUAL ONLY (except 2 named bug fixes). Keep all Alpine logic/endpoints. Vietnamese, x-text only. Do NOT touch the head cache-bust (already at ?v=glass-3). Do NOT touch backend.

New helpers available in styles.css:
- `.avatar-fallback` — branded tint for avatar initials (replaces `bg-slate-300 ... text-slate-600`).
- `.pro-btn-sm` — compact button size (padding+radius). Use for small inline actions.
- `.divider-soft` — lighter hairline; pair with Tailwind `border-t`/`border-b` (e.g. `border-t divider-soft`).
- `window.timeAgo(iso)` — relative Vietnamese time (UTC-aware). Use for feed post + comment times. Keep `formatDate` only for date ranges (profile exp/edu).

Global conventions to enforce:
- ONE button radius: `rounded-lg` everywhere (replace `rounded`, `rounded-xl` on buttons/inputs). Never `text-xs` for a PRIMARY action; primary = `pro-btn px-4 py-2 rounded-lg text-sm`, compact = `pro-btn-sm` or `pro-btn-ghost ... rounded-lg`.
- Avatar initials fallback: use `avatar-fallback` (drop `bg-slate-300`/`text-slate-600`).
- Card dividers: bare `border-t`/`border-b` → add `divider-soft`.
- Off-brand `text-blue-600` → `text-navy`.
- Min text size `text-xs` (no `text-[10px]`).
