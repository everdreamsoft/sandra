---
name: memory
description: Display a summary of everything known about the user
---

Build a comprehensive profile of the user from Sandra memory.

Steps:
1. Use `sandra_list_factories` to see all entity types
2. Use `sandra_search` to find the main user entity (person)
3. Use `sandra_get_triplets` on the user entity to load ALL relationships
4. For each relationship, load the connected entity details
5. Organize the information into categories:
   - Identity (name, birthday, contact)
   - Professional (companies, roles, education)
   - Contacts (people known and their roles)
   - Preferences (likes, dislikes)
   - Active tasks and reminders
   - Recent activity
6. Present it as a clean, readable profile
