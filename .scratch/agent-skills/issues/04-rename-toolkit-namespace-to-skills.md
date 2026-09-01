# 04: Rename the toolkit namespace to Skills

**What to build:** Rename the Agent Skills toolkit directory and public namespace from `AgentSkills` to the concise `Skills` name.

**Blocked by:** 03: Guide referenced files and scripts.

**Status:** claimed

- [ ] All five public feature types live under `src/Tools/Toolkits/Skills`.
- [ ] All five public feature types use the `NeuronAI\Tools\Toolkits\Skills` namespace.
- [ ] Tests and imports use the new namespace and corresponding `tests/Tools/Skills` organization.
- [ ] No `AgentSkills` production or test directory, namespace, import, or compatibility alias remains.
- [ ] Relevant tests, formatting, static analysis, PHP 8.1 compatibility checks, and diff-check pass.
