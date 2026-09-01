# 02: Load skill resources safely

**What to build:** Allow the model to progressively load textual resources belonging to an already available skill while keeping every filesystem read confined to that skill's directory.

**Blocked by:** 01: Load an Agent Skill from filesystem.

**Status:** ready-for-agent

- [ ] `skill_load_resource` loads a textual resource by registered skill name and logical relative path.
- [ ] Resource loading delegates to the repository that owns the skill and does not expose unrestricted filesystem access.
- [ ] Nested relative resources can be loaded from any directory within the skill package rather than from a hard-coded resource category.
- [ ] Empty paths, absolute paths, missing resources, and traversal outside the skill directory return deterministic errors.
- [ ] Symlinks resolving outside the owning skill directory are rejected, while valid confined paths remain readable.
- [ ] Unsupported binary content produces a model-readable unsupported-content result without embedding arbitrary binary bytes.
- [ ] Scripts are readable only as resources and are never executed by the Skill Toolkit.
- [ ] Tool run tracking distinguishes different skill names and resource paths.
- [ ] Tests cover successful progressive disclosure and every filesystem confinement rule through the public tool and repository seams.
