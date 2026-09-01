# 01: Load an Agent Skill from filesystem

**What to build:** Allow an Agent to register the Skill Toolkit with one explicit filesystem skills root, disclose the discovered skill catalog, and load a selected skill's complete instructions through the normal Neuron tool loop.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] An Agent can opt into skill support through the existing toolkit extension point without changes to its inference protocol.
- [ ] The filesystem repository discovers direct child directories containing `SKILL.md` beneath one explicitly configured skills root.
- [ ] Discovery parses the Agent Skills frontmatter with a YAML parser and exposes valid required and optional metadata.
- [ ] Catalog disclosure includes only skill names and descriptions in deterministic order.
- [ ] Calling `skill_load` for a registered name returns that skill's complete textual instructions through an ordinary tool result.
- [ ] Full instructions are loaded lazily rather than during catalog discovery.
- [ ] Invalid skills are excluded without making unrelated valid skills unavailable, and their diagnostics remain observable to the application.
- [ ] Unknown skill names produce a deterministic, model-readable result.
- [ ] Registering the toolkit follows the existing behavior shared by chat, streaming, and structured-output Agents.
- [ ] Tests exercise the behavior through the Skill Toolkit/Agent and repository interfaces rather than parser internals.
