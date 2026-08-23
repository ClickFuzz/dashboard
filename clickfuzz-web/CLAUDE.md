# AutoSiteDesigner / ClickFuzz CRM Plugin Project

## DirectAdmin MCP Context

When accessing the DirectAdmin MCP from this project, always work relative to:

**Server path:** `/domains/clickfuzz.com/public_html/dashboard`

- **Account:** `clgorman` on `homesincda.com:2222`
- **Application:** Perfex CRM (CodeIgniter backend, Vue 3 + Bootstrap 3 + Tailwind CSS 3 frontend)
- **Domain:** `clickfuzz.com/dashboard`

All file reads, writes, and searches via the DA MCP should use paths starting with `/domains/clickfuzz.com/public_html/dashboard/`.

## Project Goal

Building a custom plugin for Perfex CRM.

## Execution Style

Always proceed with implementation directly. Do not pause to ask "should I proceed?", present a plan for approval, or ask for confirmation before building. When given a task, execute it. If something is genuinely ambiguous, make a reasonable decision and note it in the response.

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

## PASSDOWN Protocol

`PASSDOWN.md` in this directory is the project handoff document. It records feature state, architecture gotchas, and open items between sessions.

- Read `PASSDOWN.md` before making code changes in any session.
- After completing substantial work, update `PASSDOWN.md`:
  - Mark newly completed items ✓ in the feature table
  - Add any new gotchas or architectural notes discovered
  - Update the Open Items / What's Next section
- Do not erase state recorded by a prior session without replacing it with current state.
- If work is incomplete at end of session, record the stopping point explicitly in PASSDOWN.md.

## Worktree Context

Check `CLAUDE.local.md` in this directory (if it exists) for this worktree's assigned task, branch, and scope constraints. It is untracked and worktree-specific — it takes precedence over general guidance for workstream-specific decisions.

Pattern: each worktree gets a `CLAUDE.local.md` naming the task and any restrictions (e.g., "audit only, do not merge or deploy").
