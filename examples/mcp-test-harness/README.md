# MCP Test Harness

A browser-based page to exercise the Sandra memory agent end-to-end. Useful to verify that a fresh Claude Code + Sandra MCP setup actually stores and recalls information the way it should, without writing any test code yourself.

## What it does

Opens a checklist of real-world memory scenarios — storing a name, preferences, contacts, cross-session accumulation, concept reuse, and so on. For each scenario you:

1. Paste the **store** message into Claude Code (a new conversation with the Sandra agent active).
2. Later, paste the **verify** message and read Claude's answer.
3. Tick the scenario green, orange, or red in the page depending on whether recall was correct.

At the end you get a quick health score of your Sandra setup.

## How to run

No build step, no server. Just open the page in a browser:

```bash
open examples/mcp-test-harness/index.html
```

All state (results, notes) stays in the browser — nothing is sent anywhere.

## Files

| File | Role |
|---|---|
| `index.html` | Standalone page, styles, and state logic |
| `SandraTestProtocol.jsx` | React component with the full list of test scenarios |

## When to use this

- After installing Sandra MCP + the [`claude-code-agent/`](../claude-code-agent/) config, to confirm the recall/save/concept-reuse behavior works on your machine.
- When hacking on the agent prompt or the MCP tools, to regression-check that changes haven't broken basic memory flows.
- When demoing Sandra — the checklist format is a clear way to show what "AI with persistent memory" actually looks like in practice.
