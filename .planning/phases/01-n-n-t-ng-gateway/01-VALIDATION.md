---
phase: 1
slug: n-n-t-ng-gateway
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-06
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: 01-RESEARCH.md §Validation Architecture. Phase 1 is infrastructure — validation is
> integration/smoke-level (does the stack boot healthy? does login still work?), NOT unit tests.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | **None** — no PHPUnit/Pest in repo (only `php -l` lint in CI). Phase 1 uses bash/`curl` smoke tests against the running stack, matching existing CI smoke approach. PHPUnit deliberately deferred to Phase 3+ (when business logic lands). |
| **Config file** | none — Wave 0 adds `scripts/smoke-phase1.sh` |
| **Quick run command** | `curl -sf http://127.0.0.1:8000/api/health` |
| **Full suite command** | `docker compose up -d --wait && bash scripts/smoke-phase1.sh` |
| **Estimated runtime** | ~30–60 seconds (stack boot dominates) |

---

## Sampling Rate

- **After every task commit:** `php -l` on touched `.php` files (matches CI lint) + `docker compose config -q` (compose validity)
- **After every plan wave:** `docker compose up -d --wait` + relevant subset of `scripts/smoke-phase1.sh`
- **Before `/gsd-verify-work`:** full `scripts/smoke-phase1.sh` green locally, then push → CI deploy → `https://soa.duyet.vn/api/health` green
- **Max feedback latency:** ~60 seconds

---

## Per-Task Verification Map

> Task IDs are provisional (planner finalizes). Each maps a phase requirement to an automated smoke assertion.

| Req | Wave | Behavior | Threat Ref | Test Type | Automated Command | File | Status |
|-----|------|----------|------------|-----------|-------------------|------|--------|
| PLAT-05 | gate | Stack boots clean, all containers healthy, ≤9 | — | smoke | `docker compose up -d --wait && test $(docker compose ps --services \| wc -l) -le 9` | ❌ W0 `scripts/smoke-phase1.sh` | ⬜ pending |
| PLAT-01/05 | 2 | Each service `/health` → 200 `{status,db,ts}` with `db:ok` | — | smoke | loop `docker compose exec -T gateway wget -qO- http://$s-service/health` over 5 services | ❌ W0 | ⬜ pending |
| PLAT-01/D-10 | 3 | Gateway `/api/health` fan-out reports all 5 services ok | — | smoke | `curl -sf .../api/health \| grep -q '"status":"ok"'` + assert 5 service keys | ❌ W0 | ⬜ pending |
| PLAT-02 | 3 | `/api/profiles/{id}` routes to profile-service, returns profile | — | smoke | `curl -sf .../api/profiles/2 \| grep -q duyet` | ❌ W0 | ⬜ pending |
| PLAT-03 | 3 | Protected route rejects missing/invalid JWT; services trust X-User-Id | T-1-03 | smoke | `curl -s -o /dev/null -w '%{http_code}' .../api/me` → expect 401 | ❌ W0 | ⬜ pending |
| PLAT-04 | 3 | Response carries `X-Request-Id`; downstream receives it (D-12) | — | smoke | `curl -sD- .../api/health \| grep -i x-request-id`; verify rid in stub logs | ❌ W0 (log assert manual) | ⬜ pending |
| PLAT-06 | 5 | Public HTTPS health green after CI deploy | — | smoke (CI) | `curl -sf https://soa.duyet.vn/api/health` (exists in `deploy.yml:52-64`) | ✅ CI | ⬜ pending |
| PROF-01 | 4 | Register + login still work end-to-end (JWT issued) | T-1-03 | smoke | `curl -sf -XPOST .../api/auth/login -d '{"login":"duyet","password":"demo@123**"}' \| grep -q token` | ❌ W0 | ⬜ pending |
| D-06 | 4 | 5 demo accounts exist post-reseed; login works for each | — | smoke | login loop over demo/duyet/long/diep/tai | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `scripts/smoke-phase1.sh` — single script asserting: container count ≤9; all 5 service `/health` ok with `db:ok`; gateway `/api/health` ok with 5 service keys; login works for all 5 demo accounts; `/api/me` returns 401 without token; `X-Request-Id` header present. Covers PLAT-01..06 + PROF-01 + D-06/D-10.
- [ ] No framework install needed — bash + curl + docker compose only (all present).

*Existing CI already smoke-tests `/api/health`; Wave 0 adds the local pre-push smoke script so failures are caught before deploy.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| X-Request-Id propagation visible in downstream service logs | PLAT-04/D-12 | Requires correlating gateway-issued rid with stub log line across containers | `docker compose logs <svc>-service \| grep <rid>` after a request; confirm same rid |
| Production HTTPS deploy is additive (site not broken) | PLAT-06 | Live VPS, Cloudflare edge, real deploy | After push, confirm `https://soa.duyet.vn/api/health` 200 + all 5 services ok in payload |
| Live VPS `.env` has 5 new `*_SVC_DB_PASS` before deploy | D-06/D-13 | Git-ignored secret edited by hand on VPS | Confirm `.env` updated prior to Actions deploy (else stub DB users fail) |

---

## Validation Sign-Off

- [ ] All tasks have automated smoke verify or Wave 0 dependency
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 (`scripts/smoke-phase1.sh`) covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter (after planner wires task IDs)

**Approval:** pending
