# RECO Backend API — Improvement Plan (target: ≥ 9/10)

**Current: 5/10** (Symfony 6.4 / API Platform 4.1, ~149 PHP files, MySQL 8)
Architecture is sound; the score is dragged down by an active security bug, fail-open
authorization, zero tests, and a fat order processor. This plan is ordered by priority —
**P0 is an active vulnerability, do it first.**

> Definition of 9/10: no known auth/security holes, `strict_types` everywhere, a real test
> suite (≥70% on pricing/tax/workflow/authorization), no fat god-classes, static analysis
> in CI, no fail-open data paths.

---

## P0 — Security (must fix immediately)

### 0.1 Privilege escalation via `User.roles` mass-assignment 🔴
A regular user editing their own profile can submit `"roles":["ROLE_ADMIN"]` and self-promote.
- `src/Entity/User.php:78-80` — `roles` is in the `user:update` denormalization group.
- `src/Entity/User.php:43-44` — Patch/Put allow `object == user`.

**Fix:**
- Remove `roles` from `user:update` (and any non-admin write group). Put it in an
  `admin:user:write` group used only by an admin-gated operation.
- Add a dedicated admin-only operation (or `security: "is_granted('ROLE_ADMIN')"`) for role
  changes, OR a `denormalizationContext` switch by role.
- Add a Doctrine/`PreUpdate` or processor guard that rejects role changes from non-admins as
  defence-in-depth.

**Acceptance:** functional test — a `ROLE_CLIENT` user PATCHing their own profile with a
`roles` payload gets 403/ignored; an admin can still set roles.

### 0.2 Order operations bypass `OrderVoter`
`OrderVoter` (VIEW/EDIT/DELETE, draft-only edit/delete) is referenced only in
`OrderPdfController.php:37`. The Order `Delete` op has **no** `security` expression
(`src/Entity/Order.php:111`); Patch/Put check only coarse roles (`:99-110`).

**Fix:** add `security`/`securityPostDenormalize` to Order Get/Patch/Put/Delete operations
delegating to the voter (e.g. `is_granted('EDIT', object)`). Remove the self-admitted
"follow-up" note in `security.yaml` once done.

**Acceptance:** test that a `ROLE_CLIENT` user cannot delete/modify another user's non-draft
order (403), but can edit their own draft.

### 0.3 Fail-open query scoping on Orders
`src/Doctrine/OrderClientExtension.php:64` returns **unscoped** when a non-admin user has no
client → they see ALL orders. `ClientOwnershipExtension.php:69-70` correctly applies `1 = 0`
for the same case.

**Fix:** make the no-client branch fail **closed** (`1 = 0`). Then unify — see 3.1.

**Acceptance:** test that a non-admin user with no client gets an empty order collection.

### 0.4 Stop returning stack traces / scrubbing secrets
- `src/Controller/ClientProductController.php:45` returns `getTraceAsString()` in 500 JSON.
  Remove (log it, return a generic message) even though env-guarded.
- Rotate the Mailtrap SMTP credential in `.env.local`; confirm `.env.local` is git-ignored.

---

## P1 — Testing (0 → real suite; biggest single score lever)

Currently `tests/` holds only `bootstrap.php`. No `phpunit.dist.xml`, no `*Test.php`.

1. Add `phpunit.dist.xml`, a `tests/` structure (`Unit/`, `Integration/`, `Functional/`),
   and a test database config (`.env.test`).
2. **Unit** (pure logic, highest ROI):
   - `ClientAgentAuthorization` — admin bypass / missing role / company-not-agent /
     unmanaged-client / managed-client / null no-op (6 cases).
   - `PriceCalculator` + `DiscountResolver` — client price resolution, campaign stacking,
     tax derivation, qty-step rounding.
   - `OrderVoter` — every VIEW/EDIT/DELETE branch.
3. **Functional (API)** with `ApiTestCase`:
   - Auth: login, refresh, rate-limit.
   - Order create on-behalf-of (agent authorised / unauthorised / non-agent stripped).
   - The P0 regression tests (0.1–0.3).
   - Cross-client isolation: client A cannot read/patch client B's orders, addresses, users.
4. Target **≥70%** line coverage on `src/Service`, `src/Security`, `src/State`, `src/Doctrine`.

**Acceptance:** `vendor/bin/phpunit` green in CI; coverage report ≥70% on the four namespaces.

---

## P2 — Type safety & static analysis

1. Add `declare(strict_types=1);` to **all** `src/` files (currently 0/149). Enforce with a
   CS rule so new files keep it.
2. Introduce **PHPStan** (`phpstan.neon`, level 6→8 incrementally) and **php-cs-fixer**
   (PSR-12 + `declare_strict_types`). Wire both into CI.
3. Fix float identity comparisons: `$unitPrice === 0` → `abs($x) < 1e-9` or `bccomp`
   (`src/Service/PriceCalculator.php:48,90,99`).

**Acceptance:** `phpstan` passes at the agreed level in CI; CS check passes; no `=== 0` on floats.

---

## P3 — Architecture & maintainability

### 3.1 Unify the two client-scoping extensions
`ClientOwnershipExtension` and `OrderClientExtension` implement the same idea differently
(and inconsistently — see 0.3). Extract one configurable `ClientScopeExtension` (entity →
ownership path) applied uniformly. Removes the fail-open class of bug permanently.

### 3.2 Break up `OrderPriceProcessor` (554 lines, 6 concerns)
`src/State/Processor/OrderPriceProcessor.php` does pricing + qty rounding + tax + address
validation + workflow transitions + message dispatch.
- Extract `OrderPricingService` (item pricing, tax, totals).
- Extract `OrderWorkflowTransitioner` (the `determineOrderTransition` map at `:442-478` +
  apply/guard logic).
- Move dispatch to an event subscriber on `workflow.order.*` (some already exists —
  consolidate; remove duplicate dispatch at `:164-230`).
- Processor becomes thin orchestration only.

### 3.3 Remove framework-bypassing code
- `src/Controller/ClientProductController.php` (456 lines) — replace in-PHP `array_slice`
  pagination/filtering (`:57-67`) with a proper API Platform resource + Doctrine paginator;
  delete the `debug-relations` endpoint (`:29`).
- Delete dead `PriceCalculator::calculateOrderTotal()` (`:31`) — the entity's
  `calculateTotalAmount()` is the live path.
- Make `getClientProductPrice()` a scoped query instead of an O(n) PHP loop
  (`PriceCalculator.php:17`) to kill the N+1.

---

## P4 — Logging & error hygiene

- Demote per-request `info` spam in `OrderPriceProcessor` (`:88,129,383-404`) to `debug`;
  stop logging full enabled-transition dumps and customer emails/prices at info level.
- Replace silent catch-log-continue on message dispatch (`:197-202`) with a retry/outbox or
  at least a surfaced failure.

---

## P5 — CI / tooling (locks the score in)

- GitHub Actions (or equivalent): `composer validate`, php-cs-fixer `--dry-run`, phpstan,
  phpunit + coverage gate, `doctrine:schema:validate`, `doctrine:migrations:migrate` against
  a throwaway DB.
- **Fix the environment blocker:** `symfony/http-client` is missing, so the container fails
  to compile (`DhlClient` can't autowire `HttpClientInterface`) — `composer require
  symfony/http-client`. Until then no console command (migrations, fixtures, tests) runs.

---

## Done-when checklist
- [ ] 0.1 roles not writable by non-admins (+ test)
- [ ] 0.2 OrderVoter wired to all Order ops (+ test)
- [ ] 0.3 order scoping fails closed (+ test)
- [ ] 0.4 no stack traces in responses; secret rotated
- [ ] PHPUnit suite ≥70% on Service/Security/State/Doctrine
- [ ] `strict_types` in 100% of `src/`; PHPStan lvl ≥6 + CS in CI
- [ ] one unified scope extension; OrderPriceProcessor split; dead code removed
- [ ] CI pipeline green end-to-end
