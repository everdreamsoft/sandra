# Examples

Practical setups that show Sandra in use.

## [`claude-code-agent/`](claude-code-agent/)

A drop-in configuration that turns Claude Code into a personal assistant with persistent memory backed by Sandra. Includes the agent prompt (`CLAUDE.md`), a session hook that loads context at the start of every conversation, and three slash commands (`/recall`, `/save`, `/memory`).

Use this if you want to try Sandra-as-memory on your own machine in under five minutes.

## [`mcp-test-harness/`](mcp-test-harness/)

A browser-based checklist to verify that your Sandra MCP setup actually stores and recalls information correctly. No build step — just open the HTML file.

Use this to validate a fresh install, or to regression-check changes to the agent prompt or MCP tools.

## Looking for a plain-PHP "hello world"?

No Claude required — see [`docs/code-samples/animal-shelter.php`](../docs/code-samples/animal-shelter.php).
