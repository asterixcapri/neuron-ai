# 02: Load skill resources safely

**What to build:** Allow the model to progressively load textual resources belonging to an already available skill while keeping every filesystem read confined to that skill's directory.

**Blocked by:** 01: Load an Agent Skill from filesystem.

**Status:** resolved

- [x] `skill_load_resource` loads a textual resource by registered skill name and logical relative path.
- [x] Resource loading delegates to the repository that owns the skill and does not expose unrestricted filesystem access.
- [x] Nested relative resources can be loaded from any directory within the skill package rather than from a hard-coded resource category.
- [x] Empty paths, absolute paths, missing resources, and traversal outside the skill directory return deterministic errors.
- [x] Symlinks resolving outside the owning skill directory are rejected, while valid confined paths remain readable.
- [x] Unsupported binary content produces a model-readable unsupported-content result without embedding arbitrary binary bytes.
- [x] Scripts are readable only as resources and are never executed by the Skill Toolkit.
- [x] Tool run tracking distinguishes different skill names and resource paths.
- [x] Tests cover successful progressive disclosure and every filesystem confinement rule through the public tool and repository seams.

## Answer

Added storage-neutral resource loading to the Skill Repository contract and exposed it through the input-tracked `skill_load_resource` tool. Filesystem reads resolve registered skill ownership, reject empty, absolute, traversing, missing, and out-of-skill paths, enforce canonical symlink confinement, return only UTF-8 text, and report unsupported binary content without returning its bytes. Tests cover progressive loading through the Agent loop, repository delegation, arbitrary nested resources, scripts as inert text, confinement failures, binary results, and distinct run keys.
