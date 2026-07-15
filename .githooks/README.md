# Git hooks

Version-controlled hooks for this repo. Enable them **once per clone**:

```bash
git config core.hooksPath .githooks
```

## pre-commit

Rejects accidental commits of database dumps / credential data:
- files whose name looks like a dump (`*dump*.sql`, `*-full-dump.sql`, `*.dump.sql`) — also blocked by `.gitignore`;
- any staged file containing `INSERT INTO` together with a bcrypt/argon password hash, regardless of filename.

Bypass for a reviewed false positive: `git commit --no-verify`.

> Background: a full production dump was once committed (see `resources/db-patches/REMEDIATION-RUNBOOK.md`). This hook is a local backstop; a CI check is the stronger guard (tracked in the backlog).
