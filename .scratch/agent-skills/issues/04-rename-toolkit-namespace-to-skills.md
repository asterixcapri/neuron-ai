# 04: Rename the toolkit namespace to Skills

**What to build:** Rename the Agent Skills toolkit directory and public namespace from `AgentSkills` to the concise `Skills` name.

**Blocked by:** 03: Guide referenced files and scripts.

**Status:** resolved

- [x] All five public feature types live under `src/Tools/Toolkits/Skills`.
- [x] All five public feature types use the `NeuronAI\Tools\Toolkits\Skills` namespace.
- [x] Tests and imports use the new namespace and corresponding `tests/Tools/Skills` organization.
- [x] No `AgentSkills` production or test directory, namespace, import, or compatibility alias remains.
- [x] Relevant tests, formatting, static analysis, PHP 8.1 compatibility checks, and diff-check pass.

## Answer

Renamed the five public Skills toolkit types from `src/Tools/Toolkits/AgentSkills` and the `NeuronAI\Tools\Toolkits\AgentSkills` namespace to `src/Tools/Toolkits/Skills` and `NeuronAI\Tools\Toolkits\Skills`. Moved the corresponding tests to `tests/Tools/Skills` and updated their namespaces and imports without retaining compatibility aliases.
