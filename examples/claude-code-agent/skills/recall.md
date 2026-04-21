---
name: recall
description: Search your memory for a topic, person, or subject
---

Search Sandra for information related to the user's query. Use multiple search strategies to find the most relevant context.

Query: $ARGUMENTS

Steps:
1. If `sandra_semantic_search` is available, use it first with the query (limit=10, threshold=0.3)
2. Also run `sandra_search` with targeted patterns extracted from the query
3. For any entities found, use `sandra_get_triplets` to load their relationships
4. Present the results naturally — as things you "remember", not as database results
5. If nothing is found, say so honestly
