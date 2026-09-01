# 03: Compose multiple skill repositories

**What to build:** Allow one Agent to present a single skill catalog assembled from filesystem and application-defined repositories while routing every instruction and resource load to the repository that owns the selected skill.

**Blocked by:** 01: Load an Agent Skill from filesystem; 02: Load skill resources safely.

**Status:** ready-for-agent

- [ ] Application code can implement the storage-neutral Skill Repository interface without modifying the Skill Toolkit.
- [ ] A composite repository presents skills from multiple repositories as one deterministic catalog.
- [ ] `skill_load` routes to the repository that owns the requested skill.
- [ ] `skill_load_resource` routes to the same owning repository as the skill instructions.
- [ ] A skill remains atomic: its instructions and resources cannot be combined implicitly from different repositories.
- [ ] Duplicate skill names across repositories fail during catalog bootstrap instead of silently shadowing one source.
- [ ] Repository order does not change duplicate-name behavior.
- [ ] Tests mix the filesystem implementation with at least one non-filesystem fake or in-memory implementation through the public repository and toolkit seams.
