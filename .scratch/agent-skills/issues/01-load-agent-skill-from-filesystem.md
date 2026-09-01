# 01: Load an Agent Skill from filesystem

**What to build:** Allow an Agent to register the Skill Toolkit with one explicit filesystem skills root, disclose the discovered skill catalog, and load a selected skill's complete instructions through the normal Neuron tool loop.

**Blocked by:** None (can start immediately).

**Status:** resolved

- [x] An Agent can opt into skill support through the existing toolkit extension point without changes to its inference protocol.
- [x] The filesystem repository discovers direct child directories containing `SKILL.md` beneath one explicitly configured skills root.
- [x] Discovery parses the Agent Skills frontmatter with a YAML parser and exposes valid required and optional metadata.
- [x] Catalog disclosure includes only skill names and descriptions in deterministic order.
- [x] Calling `skill_load` for a registered name returns that skill's complete textual instructions through an ordinary tool result.
- [x] Full instructions are loaded lazily rather than during catalog discovery.
- [x] Invalid skills are excluded without making unrelated valid skills unavailable, and their diagnostics remain observable to the application.
- [x] Unknown skill names produce a deterministic, model-readable result.
- [x] Registering the toolkit follows the existing behavior shared by chat, streaming, and structured-output Agents.
- [x] Tests exercise the behavior through the Skill Toolkit/Agent and repository interfaces rather than parser internals.

## Answer

Implemented a filesystem-backed Skill Repository and Skill Toolkit with deterministic direct-child discovery, Symfony YAML frontmatter parsing, observable diagnostics, lazy instruction loading, catalog-only guidelines, and an input-tracked `skill_load` tool exercised through the normal Agent loop.
