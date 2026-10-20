# Agent workflow

These instructions apply to the entire project through the root `AGENTS.md`.

## Start

- Read the project context and handoff in this folder, plus any more specific applicable `AGENTS.md` files.
- Inspect Git status before editing. Preserve local changes, ignored data, and user commits.
- Fetch `origin main` and inspect incoming commits. With a clean checkout, fast-forward to the fetched tip when possible. Resolve divergence carefully; never discard work or force-push.
- Work directly on `main` in the existing checkout. Do not create additional branches or worktrees.
- Inspect the current implementation before relying on the handoff; repository files are authoritative for behavior.

## Implement and validate

- Keep the user-facing workflow in Filament and preserve macOS Herd compatibility.
- Reuse existing setup scripts and dependency locks. Do not add dependencies or change locked versions without a reason tied to the task.
- Preserve authentication, administrator restrictions, public URL checks at redirects, subprocess timeouts, and escaped output.
- Never reset a user's password or application key during repeat setup. Keep local starter-account creation out of production.
- Do not commit `.env`, databases, credentials, virtual environments, dependency directories, generated assets, or machine-specific runtime paths.
- Keep TLS, package signatures, and artifact verification enabled.
- Run checks relevant to the change. For PHP/Python behavior changes, the main commands are:
  - `php artisan test`
  - `.venv/bin/python -m unittest discover -s tests/Python`
- Check meaningful UI changes in a browser when available. Distinguish automated checks, browser checks, external live fetches, and macOS-specific verification.
- Documentation-only edits need link/content checks and `git diff --check`; do not rerun unrelated application suites.
- Report missing tools or network limitations accurately. Do not weaken application protections to make a check pass.

## Finish

- Update `README.md` when setup or usage changes.
- Replace `instructions/HANDOFF.md` with a short account of the current state, actual validation, and outstanding work. Keep durable preferences in `instructions/README.md`; do not append a running transcript.
- Review the diff and staged files. Commit only the intended changes with a clear message.
- Recheck remote `main` before pushing. Incorporate incoming work without force-pushing, and rerun affected checks if needed.
- Push to `origin main` and verify the remote commit. If pushing is blocked, preserve the local commit and report the blocker.
- Tell the user what changed, what was checked, and what to pull or run locally. Do not claim a push or test succeeded without verification.
