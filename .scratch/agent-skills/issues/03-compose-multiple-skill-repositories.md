# 03: Compose multiple skill repositories

**What to build:** Allow one Agent to present a single skill catalog assembled from filesystem and application-defined repositories while routing every instruction and resource load to the repository that owns the selected skill.

**Blocked by:** 01: Load an Agent Skill from filesystem; 02: Load skill resources safely.

**Status:** resolved

- [x] Application code can implement the storage-neutral Skill Repository interface without modifying the Skill Toolkit.
- [x] A composite repository presents skills from multiple repositories as one deterministic catalog.
- [x] `skill_load` routes to the repository that owns the requested skill.
- [x] `skill_load_resource` routes to the same owning repository as the skill instructions.
- [x] A skill remains atomic: its instructions and resources cannot be combined implicitly from different repositories.
- [x] Duplicate skill names across repositories fail during catalog bootstrap instead of silently shadowing one source.
- [x] Repository order does not change duplicate-name behavior.
- [x] Tests mix the filesystem implementation with at least one non-filesystem fake or in-memory implementation through the public repository and toolkit seams.

## Answer

Added `CompositeSkillRepository` as a storage-neutral repository implementation that snapshots and alphabetically sorts catalogs during construction, records one owning repository per skill, and routes both instruction and resource loads exclusively to that owner. Duplicate names are collected and reported as a deterministic bootstrap error independent of repository order, while repository diagnostics are aggregated. Tests combine filesystem and in-memory repositories through the public repository and toolkit seams, verify catalog and tool routing, prove failed resource loads never fall back to another repository, and cover order-independent collisions.
