# 01: Register and load a filesystem skill

**What to build:** Allow an application to register a Skills toolkit backed by one explicit filesystem root, disclose a compact catalog through the Agent's normal toolkit guidelines, and load one selected skill's Markdown instructions through the normal tool loop.

**Blocked by:** None (can start immediately).

**Status:** resolved

- [x] The public feature consists of `SkillCatalogEntry`, `SkillRepositoryInterface`, `FileSystemSkillRepository`, `SkillToolkit`, and `SkillTool`, colocated under the Skills toolkit namespace.
- [x] `SkillCatalogEntry` contains only a skill name and description, and the repository interface exposes catalog discovery plus a textual read operation.
- [x] The filesystem repository receives one explicit root and snapshots an alphabetically ordered catalog from valid direct-child directories containing `SKILL.md`.
- [x] Discovery never scans project ancestors, user directories, `.agents`, `.claude`, or other conventional locations automatically.
- [x] Frontmatter discovery reads only first-column `name:` and `description:` lines, splits each at the first colon, trims the values, and ignores all other lines and optional metadata.
- [x] A skill is silently omitted when frontmatter delimiters or required values are missing, its required values violate the agreed Agent Skills length or naming constraints, or its name differs from the logical direct-child directory name.
- [x] Complete skill-directory symlinks are supported even when their targets are outside the configured root; the logical link name identifies the skill and its canonical target becomes the package boundary.
- [x] `SKILL.md` must resolve to a regular file inside the canonical skill directory; a manifest symlink escaping that directory is not catalogued.
- [x] `SkillToolkit` contributes only the catalog and concise loading guidance through normal toolkit guidelines; full instruction bodies are absent from the initial system prompt.
- [x] When at least one skill exists, the toolkit exposes exactly one model-facing tool named `skill`, whose required name input is constrained to the catalog snapshot.
- [x] Calling `skill` with a registered name returns only the trimmed Markdown body after the frontmatter as an ordinary textual tool result.
- [x] Instruction bodies are read lazily on every invocation, so edits made after discovery are visible while the catalog names and descriptions remain unchanged.
- [x] An unavailable skill produces concise model-readable text instead of terminating the Agent loop; unexpected failures still use Neuron's ordinary exception behavior.
- [x] When no valid skills exist, the toolkit contributes neither guidelines nor tools and does not fail Agent bootstrap.
- [x] Registering the toolkit with a real Agent and fake provider verifies the catalog, tool schema, instruction load, normal tool result, and empty-catalog behavior without modifying Agent or inference internals.
- [x] The superseded two-tool API, composite repository, active-skill types, lifecycle middleware, and related Agent/core changes are removed rather than retained as compatibility layers.
- [x] The implementation introduces no Composer package or PHP-extension dependency and does not claim to implement a complete YAML parser.
- [x] Relevant tests, formatting, static analysis, and type-coverage checks pass on supported PHP versions.

## Answer

Implemented the filesystem-backed Skills toolkit with a fixed compact catalog, minimal frontmatter discovery, lazy instruction reads, one model-facing `skill` tool, empty-catalog behavior, and no Agent lifecycle or parser dependency.
