# Concept Deduplication Strategy

## The Problem

System concepts are Sandra's vocabulary. Each one is loaded in RAM at boot. If the concept count explodes due to duplicates or overly specific concepts, it wastes memory and degrades performance.

The root cause: an AI agent that creates new concepts instead of reusing existing ones.

```
BAD:  likes_chocolate(141), likes_pizza(142), likes_music(143)
      → 3 concepts for what should be 1 concept + 3 triplets

GOOD: likes(140) → triplets: Shaban→likes→chocolate, Shaban→likes→pizza
      → 1 concept, reused 3 times
```

## Two Defense Layers

### Layer 1: Graph-Only (always active, no dependencies)

The LLM is itself a semantic engine. When it sees `likes` in a concept list, it KNOWS that "adore", "love", "appreciate" are covered by the same concept.

The key is to make the LLM **look before creating**:

```
Step 1: sandra_list_concepts("%like%")    → targeted search, small results
Step 2: Review 5-10 results              → LLM understands synonyms
Step 3: Reuse or create                  → informed decision
```

**Critical**: the search must be TARGETED (pattern-based), not exhaustive. This keeps the context window small:

| Approach | Tokens consumed | Effective |
|----------|----------------|-----------|
| List ALL concepts | 5'000-50'000 | No — floods context |
| List with "%like%" pattern | 50-100 | Yes — focused results |
| Semantic search (5 results) | ~50 | Yes — best precision |

This is enforced via MCP instructions that tell the LLM:
- NEVER list all concepts without a filter
- ALWAYS search with a keyword pattern before creating
- Use general verbs, put specificity in the triplet targets

### Layer 2: Embeddings (when available, catches what graph misses)

Semantic search catches cases that pattern matching cannot:

| Scenario | Pattern search finds it? | Semantic search finds it? |
|----------|------------------------|--------------------------|
| "adore" when "likes" exists | Only if you search "%like%" | Yes — synonyms are close in vector space |
| "UNIGE" when "Universite de Geneve" exists | Only if you search "%uni%" | Yes — same concept |
| "financement" when "funded_by" exists | Only if you think to search "%fund%" | Yes — related meaning |
| "aime" (FR) when "likes" (EN) exists | No — different language | Yes — cross-lingual similarity |

The workflow with embeddings:

```
Agent wants to create concept "adore"
  → sandra_semantic_search("adore", limit=5, threshold=0.5)
  → Finds: likes (0.85 similarity)
  → Agent reuses "likes" instead of creating "adore"
  → Context cost: ~50 tokens (5 results)
```

## Context Window Budget

Every MCP tool call consumes context. The dedup check must be lightweight:

```
Budget per concept creation check:
  - Without embeddings: ~100 tokens (pattern search, 10 results)
  - With embeddings: ~50 tokens (semantic search, 5 results)
  - NEVER: ~5'000+ tokens (listing all concepts)
```

This matters because an agent creating 10 entities in a batch would consume:
- Smart approach: 10 x 100 tokens = 1'000 tokens for dedup
- Naive approach: 10 x 5'000 tokens = 50'000 tokens wasted

## Implementation Status

### Done
- MCP instructions updated with Concept Reuse Protocol
- Anti-patterns documented in instructions
- `sandra_semantic_search` available for dedup checks
- `sandra_embed_all` for backfilling existing entity embeddings

### Future (when needed)
- **Auto-suggest on create**: `sandra_create_concept` could automatically return similar existing concepts in its response, so the LLM sees them without an extra call
- **Concept clustering**: periodic job that groups similar concepts and proposes merges
- **Hard limit**: reject concept creation if a very similar concept exists (threshold > 0.95)

## Design Principles

1. **Concepts are vocabulary, not data.** "likes" is a word. "chocolate" is a word. The combination "Shaban likes chocolate" is a triplet (data). Never encode data in concept names.

2. **The LLM is the semantic engine.** Sandra stores structure. The LLM understands meaning. Don't try to replicate NLP in PHP — leverage the LLM's natural understanding via good instructions.

3. **Small context, big impact.** A targeted search returning 5 results is better than dumping the full vocabulary. The dedup check should be invisible in the context budget.

4. **Works without embeddings.** The graph-only protocol (pattern search + smart instructions) handles 90% of cases. Embeddings are the safety net for the remaining 10%.

5. **Fail safe.** A duplicate concept is annoying but not catastrophic. A failed entity creation because of an overzealous dedup check is worse. Always err on the side of creating.
