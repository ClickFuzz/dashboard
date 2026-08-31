# ClickFuzz Web — Claude Rules

## DirectAdmin MCP Context

When accessing the DirectAdmin MCP from this project, always work relative to:

**Server path:** `/domains/clickfuzz.com/public_html/dashboard`

- **Account:** `clgorman` on `homesincda.com:2222`
- **Application:** Perfex CRM (CodeIgniter backend, Vue 3 + Bootstrap 3 + Tailwind CSS 3 frontend)
- **Domain:** `clickfuzz.com/dashboard`

All file reads, writes, and searches via the DA MCP should use paths starting with `/domains/clickfuzz.com/public_html/dashboard/`.

## Project Goal

Building a custom plugin (module) for Perfex CRM: ClickFuzz Web (`modules/pitchsnap/`).

## Execution Style

Always proceed with implementation directly. Do not pause to ask "should I proceed?", present a plan for approval, or ask for confirmation before building. When given a task, execute it. If something is genuinely ambiguous, make a reasonable decision and note it in the response.

## Architecture Rules

Before implementing any feature, read **`docs/architecture-rules.md`**. It documents mandatory patterns:

1. **POST guard** — use `$this->input->method() !== 'post'`, never `!$this->input->post()`
2. **Tab redirects** — always append `#tab-{name}` to detail page redirects so users land on the right tab
3. **In-page actions** — prefer AJAX + JSON over form POST for actions inside tabs
4. **Media paths** — always use `clickfuzz_web_media_dir()` / `clickfuzz_web_media_url()`, never raw paths
5. **CSRF** — include CSRF field in every form; see stale-token warning for AJAX+form combos

## Global Development Rules

- Keep code simple and robust.
- Preserve all existing functionality unless explicitly asked to change it.
- Before changing code to fix a bug, inspect the order of operations and logic flow first.
- Do not refactor unrelated code.
- Make the smallest change necessary to accomplish the task.
- Never use placeholder code in a requested complete function.
- Verify changes do not break existing functionality.
- Do not expose secrets, API keys, credentials, or sensitive configuration.
- Before destructive operations, verify exactly what will be affected.

## Git & Worktree Safety

Each Claude session runs inside one git worktree. Treat the worktree as your sandbox.

- Before making any changes: run `git branch` to confirm the expected branch, then `git status` to confirm no unexpected dirty state.
- Work only within this worktree's files. Do not read from or write to another worktree's directory, or the main checkout, unless explicitly instructed.
- Never switch branches during a session.
- Never merge into `main` without explicit instruction.
- Never run `git push` unless explicitly asked.
- Never run destructive git commands (`reset --hard`, `checkout .`, `restore .`, `clean -f`, `branch -D`) without explicit approval.
- Before finishing any task: run `git diff` and `git status` to verify only task-related changes are present.
- Only create commits when the user explicitly asks for one.

## Required Reading — Before Substantial Work

Before making code changes in any session, read these in order:

1. The umbrella rules at the repo root `CLAUDE.md` (already loaded by the session environment)
2. **This file** — `clickfuzz-web/CLAUDE.md`
3. **Global passdown** — `clickfuzz-web/PASSDOWN.md` (overall architecture, production state, active worktrees, blockers)
4. **Workstream doc** — `clickfuzz-web/docs/workstreams/[workstream].md` for the subsystem you are touching
5. **Worktree context** — `clickfuzz-web/CLAUDE.local.md` if it exists (task scope, branch, exclusions — takes precedence for workstream-specific decisions)

Reading order matters: the local file may restrict or override general guidance.

## Required Updates — Before Finishing Substantial Work

After completing substantial work, before committing:

1. Update the relevant **workstream doc** (`docs/workstreams/[workstream].md`):
   - Move newly confirmed functionality into **Confirmed Working**
   - Move code that exists but is untested into **Implemented / Needs Retesting**
   - Update **In Progress** to reflect actual current state
   - Add new items to **Known Issues / Risks** if discovered
   - Update **Next** to reflect the logical next steps
   - Add a short dated entry to **History**

2. Update the **global PASSDOWN** (`PASSDOWN.md`) only when the change is genuinely global:
   - New cross-workstream architectural decisions
   - Change in production baseline or main commit hash
   - New active worktrees or branches
   - New global blockers

3. Do NOT casually modify unrelated workstream documents.

## PASSDOWN Protocol (legacy behavior — now extended by Required Updates above)

`PASSDOWN.md` in this directory is the global project handoff document.

- Read it before making code changes.
- Do not erase state recorded by a prior session without replacing it with current state.
- If work is incomplete at end of session, record the stopping point explicitly.

## Worktree Context

Check `CLAUDE.local.md` in this directory (if it exists) for this worktree's assigned task, branch, and scope constraints. It is untracked and worktree-specific — it takes precedence over general guidance for workstream-specific decisions.

Pattern: each worktree gets a `CLAUDE.local.md` naming the task and any restrictions (e.g., "audit only, do not merge or deploy").
