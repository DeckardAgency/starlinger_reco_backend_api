# CRITICAL Remediation — Committed Database Dump

**What happened:** `resources/db-patches/2026-04-15-full-dump.sql` (471 KB) was committed to
the repository (first appears in commit `327387b`). It contained **real production-adjacent
data**: ~50 `user` rows with `$2y$` bcrypt password hashes, `refresh_tokens` rows, and PII
across `client`, `address`, `order`, and `order_item`. Emails were a mix of real
staff/partner accounts and test rows.

The file has been **removed from the working tree** and `.gitignore` now blocks DB dumps
from being re-committed. **That is not sufficient** — the data still exists in git history
and any existing clone/fork. Complete the steps below.

> These steps are destructive and/or operational. Run them deliberately, in order, and
> coordinate with anyone who has a clone.

---

## 1. Purge the file from git history

Removing the file in a new commit does **not** remove it from history. Rewrite history.

**Option A — git-filter-repo (recommended):**
```bash
# from a fresh, mirror clone
pip install git-filter-repo   # if not installed
git filter-repo --path resources/db-patches/2026-04-15-full-dump.sql --invert-paths
```

**Option B — BFG:**
```bash
bfg --delete-files 2026-04-15-full-dump.sql
git reflog expire --expire=now --all && git gc --prune=now --aggressive
```

Then force-push the rewritten history:
```bash
git push --force-with-lease origin <branch>   # repeat for every affected branch/tag
```

**After the rewrite:**
- Everyone with a clone must **re-clone** (their old history still contains the file).
- If the repo is on a hosted platform (GitHub/GitLab/Bitbucket), the blob may persist in
  cached views / PRs — open a support request to purge cached blobs, and delete any forks.

## 2. Invalidate all refresh tokens

The dump contained `refresh_tokens`. Assume they are compromised; force everyone to
re-authenticate.

```sql
DELETE FROM refresh_tokens;
```
(Access-token JWTs are short-lived — TTL 24h per `lexik_jwt_authentication.yaml` — and will
expire on their own; forcing a re-login via refresh-token deletion is the meaningful action.)

## 3. Force password reset for exposed users

All ~50 users in the dump must be treated as exposed (bcrypt is slow to crack, but weak
passwords are still at risk). Options, in order of preference:
- Trigger the app's existing password-reset flow for each affected user
  (`POST /api/auth/forgot-password`), **or**
- Null their password hash to force a reset on next login, **or**
- Notify users out-of-band to change their password.

The exact user list is the set of `INSERT INTO \`user\`` rows in the original dump (recover
from history before purging if you need the addresses).

## 4. Rotate secrets that lived alongside the repo

Rotate anything that could have been exposed to whoever had repo access:
- `APP_SECRET`
- `JWT_PASSPHRASE` (and regenerate the JWT keypair if the passphrase changes)
- `DHL_API_KEY` and any other third-party keys configured for the affected environments

## 5. Prevent recurrence

- `.gitignore` now blocks `*-full-dump.sql`, `*dump*.sql`, `*.dump.sql` under
  `resources/db-patches/`. Keep only reviewed, **data-free** schema patches here.
- Consider a pre-commit hook / CI check that rejects large SQL files or files containing
  `INSERT INTO` + `$2y$` hashes.
- For local seeding, use a synthetic fixture (e.g. `LoadTestDataCommand`) rather than a
  production dump.

---

## Checklist

- [ ] History rewritten (filter-repo/BFG) on all branches & tags
- [ ] Force-pushed; team re-cloned; forks deleted; hosted cache purge requested
- [ ] `DELETE FROM refresh_tokens;` executed in each environment
- [ ] Password reset forced / users notified for all exposed accounts
- [ ] `APP_SECRET`, `JWT_PASSPHRASE` (+ JWT keypair), `DHL_API_KEY` rotated
- [ ] Pre-commit / CI guard against dumps added
