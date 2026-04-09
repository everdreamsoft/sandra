---
name: save
description: Analyze the current conversation and save new information to memory
---

Review the current conversation and extract any new information worth remembering. Save it to Sandra.

Steps:
1. Identify new facts, people, preferences, tasks, or relationships mentioned in the conversation
2. For each piece of information:
   a. Search Sandra to check if it already exists (`sandra_search` or `sandra_semantic_search`)
   b. If it exists: update the entity with new details (`sandra_update_entity`)
   c. If it's new: create the appropriate entity and link it with triplets (`sandra_batch`)
3. Follow the Concept Reuse Protocol — search for existing concepts before creating new ones
4. Report what was saved in a brief summary
