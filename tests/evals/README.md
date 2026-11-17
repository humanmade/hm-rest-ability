# Evals

The end-to-end tests prove the MCP server answers correctly. The evals ask a different question: can an agent work out how to use the tools from their descriptions and schemas alone? That is what regresses quietly when someone rewords a description, changes a schema or changes the confirmation flow.

The evals run locally only and are not part of CI. A model is not deterministic, so a failure is a prompt to go and look, not a build break.

## Prerequisites

- A logged-in `claude` CLI on your PATH. The harness is your own Claude Code and bills to your subscription, so no API key is involved.
- `npm ci` and `composer install` have been run.

## Running

```bash
npm run eval
npm run eval -- --dry-run
npm run eval -- --only=create-post,delete-post --repeat=3
npm run eval -- --model=opus
```

The harness boots Playground, writes an MCP config that points Claude Code at the site, and runs `claude -p` with each task. It prints one line per scenario and writes a JSON report to `test-results/evals.json`.

- `--dry-run` runs setup and the graders with no agent and asserts every scenario fails. A grader that passes when nothing happened is testing nothing.
- `--only=<ids>` runs the listed scenarios, comma separated.
- `--repeat=<n>` runs each scenario `n` times. A scenario passes when more than half its runs pass.
- `--model=<name>` picks the Claude model. The default is `sonnet`, or the `EVAL_MODEL` environment variable.
- `--output=<path>` sets where the JSON report goes.

If IPv4 loopback is slow on your machine, boot Playground yourself on `localhost` and set `WP_BASE_URL=http://localhost:<port>`. The harness then uses that server instead of starting one.

Two Claude Code flags matter. `--restricted` removes Bash and the other code-running tools, so the agent can't reach the REST API directly and pass without using MCP. `--strict-mcp-config` ignores your own MCP servers, so a run only sees the test site.

## Confirmation

On MCP 2026-07-28, every DELETE and every write to a site-config route asks the user to confirm through elicitation. Claude Code speaks that protocol, but headless `claude -p` answers the prompt with a non-accept, so the call doesn't run.

A scenario that sets `acceptElicitation: true` runs with an `Elicitation` hook (`accept-elicitation.mjs`, passed with `--settings`) that accepts the form. The form has no fields, so Accept is the confirmation. A scenario without it leaves the confirmation unanswered, which is how `delete-declined` checks that nothing is deleted and the agent says why.

## Scenarios

Scenarios live in `tests/evals/scenarios/`, one module per file. Each exports:

- `id`: a unique name.
- `task`: the plain English request given to the agent.
- `setup(wp)`: optional. Creates the starting state and may return a state object, such as the IDs of the posts it created.
- `grade(wp, said, state, toolCalls)`: returns `{ pass, reason }`. `wp` is an admin REST connection, `said` is the agent's final text, `state` is what `setup` returned, and `toolCalls` is a list of `{ name, input }` for every tool the agent called.
- `teardown(wp, state)`: optional. Runs after grading, pass or fail, and receives the same `state`. Use it to restore anything the reset between scenarios doesn't cover, such as site settings.
- `role`: optional. The account the agent connects as, `admin` by default.
- `acceptElicitation`: optional. Accepts confirmation prompts, `false` by default.

Grade the state of the site, not what the agent says about it. Have `setup` return the IDs it creates and grade those posts by ID, not by title, so a leftover or a second post with the same title can't change the result. Use `said` only for scenarios that expect the agent to explain why it stopped, and pair it with a state check. Use `toolCalls` when the state alone can't tell a refused call from a call that never happened. A run where the agent itself fails never passes, whatever the grader returns. A grader that would also pass when no agent ran needs a second condition, and `--dry-run` catches that. Between scenarios the harness removes all posts, media and categories, so setup only has to create what it needs.
