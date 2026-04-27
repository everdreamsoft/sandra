# Multi-agent systems with Sandra

> Most agent frameworks treat agent definitions and agent memory as
> separate concerns. Sandra unifies them: every agent is itself an
> entity in the same graph it reads from. This page is for builders
> of multi-agent systems — if you're building a single-agent app, you
> can safely skip it.

## The pattern

In CrewAI, LangGraph, AutoGen, the multi-agent topology is defined in
code. Each agent is a Python object with a string mission. Other agents
in the system cannot discover or read those missions at runtime — the
relationships between them are hardcoded.

In Sandra, an agent is just another entity in the graph.

```
Entity(diana_success_bot)
  refs: { role: "customer_success",
          description: "Maintains user profiles, curates feed ranking
                        per user, creates personalized cards. All
                        personalized cards reviewed by Eleonora
                        before publication." }
  ─ reviewed_by → Entity(eleonora_orchestrator)
  ─ produces    → Concept(personalized_card)
  ─ depends_on  → Entity(charlie_intel_bot)
```

Any agent connected to the same Sandra instance can call:

- `sandra_list_entities("agent")` — discover other agents
- `sandra_get_entity` — read another agent's mission
- `sandra_search(role: "...")` — find one whose role matches a need
- `sandra_update_entity` — update its own mission as it learns

The same MCP tools used to query memory now navigate the agent
registry. The vocabulary is unified.

## Five concrete patterns this enables

### 1. Self-improving agents

An agent updates its own mission based on what it learned during
execution.

- *In CrewAI*: requires editing source files and redeploying.
- *In Sandra*: a single `sandra_update_entity` call.

### 2. Dynamic team composition

A meta-agent reads the registry and assembles a team for an incoming
task.

- *In CrewAI*: requires preconfiguring all possible team configurations.
- *In Sandra*: `sandra_search(factory: "agent", role: "...")` returns
  whoever fits, ranked by recency, capability, or any ref you defined.

### 3. Audit and governance

"What does each agent claim it does? Which agents touch user PII?
Which agents have an explicit forbidden-action list?"

- *In CrewAI*: grep the codebase, hope the comments are current.
- *In Sandra*: triplet queries. Auditable, queryable, current by
  construction.

### 4. Multi-tenant deployment

Thirty customer instances, each with an agent customized to that
customer, all sharing common knowledge of the product.

- *In CrewAI*: deploy 30 forks or pass massive config objects.
- *In Sandra*: 30 agent entities + shared concepts. Adding a customer
  is one `sandra_create_entity` call away.

### 5. Reflective reasoning

An agent reasons about its own role mid-execution: *"I am a
customer-success agent. This issue is technical, so I should hand off
to charlie_intel_bot or bob_news_bot."*

The agent reads its own mission with the same MCP tool it uses to read
about Alice or Bob. There is no boundary between "self-knowledge" and
"world-knowledge."

## Comparison

| Approach | Agent definition | Discoverable at runtime | Cross-agent vocabulary |
|---|---|---|---|
| **CrewAI** | Python code | No | No |
| **LangGraph** | Python code | No (state object only) | Shared state |
| **AutoGen** | Python code | Within GroupChat only | No |
| **OpenAI Assistants API** | Server-managed records | Yes (flat list) | No |
| **Sandra** | Entities in shared graph | Yes (queryable graph) | Yes (concept registry) |

OpenAI Assistants API is the closest in spirit: a managed registry of
agent definitions you can list and read. The differences are:

- **Vendor lock-in**: OpenAI Assistants only run on OpenAI models.
  Sandra is model-agnostic via MCP.
- **Flat structure**: Assistants don't have relationships to each
  other. Sandra agents have explicit triplets — `reviewed_by`,
  `depends_on`, `produces` — that the system can traverse.
- **Separate from memory**: Assistants live in one API, conversations
  in another. Sandra unifies agent registry and agent memory in one
  graph, queryable through one set of tools.

## When this pattern earns its keep

- You're building 5+ agents that need to coordinate.
- Your team composition changes per customer, per task, or per tenant.
- You need audit and governance over what agents claim to do.
- You want agents to evolve their own missions as they learn.
- You expect to add or retire agents without redeploying code.

## When you should skip it

- You have a single agent and a CLAUDE.md or system prompt is enough.
- Your team is static, defined once, and changes rarely.
- You don't need runtime introspection — you trust the code that
  spawned the agents.
- You're prototyping. A YAML file is faster.

## Production example: Eleonora

The example agent shown above (Diana, customer_success) is a real
agent running in production at EverdreamSoft, alongside Bob
(news_intelligence), Charlie (company_intelligence), and Eleonora
herself (the orchestrator). All four agents read each other's missions
from the same Sandra instance they use as memory. The relationship
*"all personalized cards reviewed by Eleonora before publication"* is
not a comment in code — it is a triplet in the graph that any of
them can query.

This pattern emerged organically from running real multi-agent
workflows over time. We did not design Sandra to be a multi-agent
substrate; the property fell out of treating agents the same way we
treat any other concept. That is itself a clue about the shape of
the problem.
