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
