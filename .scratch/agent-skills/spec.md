# Agent Skills support for Neuron AI 3

Status: ready-for-agent

## Problem Statement

Neuron AI 3 has a mature tool and toolkit system, but it cannot consume skills compatible with the Agent Skills specification. Applications currently need to build their own catalog, parse `SKILL.md`, expose instructions to the model, resolve linked resources, and secure filesystem access.

An earlier upstream proposal approached skills as a separate execution subsystem with custom activation markers and declarative HTTP, shell, PHP, queue, and MCP executors. That scope duplicates capabilities already represented by Neuron tools and makes installing instructions equivalent to granting execution capabilities.

Neuron needs a smaller integration that treats a skill as a package of metadata, instructions, and resources. Applications must be able to choose their skills explicitly, mix skills loaded from different storage mechanisms, and expose them through Neuron's existing tool loop without giving the model unrestricted filesystem access.

## Solution

Add Agent Skills support to Neuron AI 3 as a toolkit backed by a repository interface.

The toolkit publishes a compact catalog containing each registered skill's name and description. The model loads complete instructions through `skill_load` and loads referenced content on demand through `skill_load_resource`. Both operations use ordinary Neuron tool calls and results.

Skill storage is hidden behind `SkillRepositoryInterface`. A filesystem implementation loads Agent Skills from one explicitly configured skills root. A composite implementation combines skills from multiple repositories, allowing one Agent to use filesystem, database, remote, or application-defined sources simultaneously. Database and remote implementations are supplied by applications rather than the initial framework implementation.

Registering the toolkit is the only integration required on an Agent. Neuron does not search conventional project or user directories automatically. The application chooses the repositories and roots available to each Agent.

The skill subsystem reads instructions and resources but never executes them. Scripts remain resources; execution requires a separately registered Neuron tool and its own authorization policy.

## User Stories

1. As a Neuron application developer, I want to use skills compatible with Agent Skills, so that I can reuse portable instruction packages.
2. As a Neuron application developer, I want to register skill support as a normal toolkit, so that I do not need a separate Agent lifecycle.
3. As an Agent author, I want to choose explicitly which skill repositories an Agent can access, so that skills are never discovered unexpectedly.
4. As an Agent author, I want to register one skills root instead of every skill directory individually, so that adding a skill below that root does not require changing Agent code.
5. As an Agent author, I want only direct child directories containing `SKILL.md` to be discovered, so that repository traversal is deterministic and bounded.
6. As an Agent author, I want the model to initially receive only skill names and descriptions, so that many available skills do not flood the context with instructions.
7. As an Agent user, I want the model to load a relevant skill before following it, so that specialized behavior is available only when needed.
8. As an Agent user, I want skill activation to use provider-native tool calling, so that activation does not depend on brittle textual markers.
9. As an Agent user, I want loaded skill instructions to remain effective throughout the conversation, so that behavior does not silently change after history trimming or summarization.
10. As an Agent user, I want duplicate skill loads to avoid duplicating instructions in context, so that repeated calls do not waste context.
11. As a skill author, I want instructions to reference additional files by relative path, so that detailed material can remain outside the main `SKILL.md`.
12. As a skill author, I want resources to load only when requested, so that optional material preserves progressive disclosure.
13. As a skill author, I want arbitrary files within the skill package to be addressable as resources, so that the framework does not hard-code only `references`, `assets`, or `scripts` directories.
14. As a security-conscious application developer, I want resource paths confined to their owning skill, so that path traversal cannot expose unrelated files.
15. As a security-conscious application developer, I want symlinks that resolve outside the skill root rejected, so that confinement cannot be bypassed indirectly.
16. As an application developer, I want scripts treated as readable resources rather than implicit executables, so that installing a skill does not grant code-execution capability.
17. As an application developer, I want skill content to come from a database, so that skills need not live on the Agent host filesystem.
18. As an application developer, I want skill content to come from a remote system, so that centrally managed skill catalogs can be used without changing the toolkit.
19. As an application developer, I want filesystem, database, remote, and in-memory skills in one Agent catalog, so that storage choice is transparent to the model.
20. As a repository implementer, I want a storage-neutral contract for catalog, instruction, and resource loading, so that custom repositories integrate without modifying Neuron.
21. As a repository implementer, I want resource identifiers to be logical paths relative to a skill, so that they can map to files, database rows, object keys, or remote endpoints.
22. As an application developer, I want one repository to own each complete skill, so that its instructions and resources have consistent storage and failure behavior.
23. As an application developer, I want duplicate skill names across repositories reported during bootstrap, so that one source cannot silently shadow another.
24. As a skill author, I want malformed required metadata reported clearly, so that invalid skills do not fail later during a model interaction.
25. As an Agent user, I want an unknown skill or resource to produce a useful tool error, so that the model can recover without an opaque runtime failure.
26. As a framework maintainer, I want skill tool calls tracked by their inputs, so that loading different skills or resources does not consume one shared run key.
27. As a framework maintainer, I want the feature to work with Neuron AI 3 chat, streaming, and structured-output Agents through the existing toolkit bootstrap, so that the integration does not fork inference behavior.
28. As a framework maintainer, I want filesystem parsing and access isolated from toolkit behavior, so that storage-specific changes remain local.
29. As a framework maintainer, I want tests to exercise public behavior through the Agent/toolkit and repository seams, so that implementation can change without rewriting the specification tests.
30. As a framework maintainer, I want the first implementation to remain read-only and text-oriented, so that Agent Skills support does not require redesigning every provider's tool-result representation.

## Implementation Decisions

- The feature targets Neuron AI 3.
- A Skill is a package containing metadata, instructions, and resources. It is not an executable tool.
- A Skill Resource is addressable content owned by one Skill.
- Skill Load is the operation that places a Skill's complete instructions into model context.
- Skill Resource Load is the operation that places one requested resource into model context.
- Skill support is exposed as a toolkit using Neuron's existing toolkit guidelines and tool bootstrap behavior.
- The toolkit exposes exactly two model-facing operations in the initial implementation: `skill_load` and `skill_load_resource`.
- The toolkit's guidelines contain the available catalog of skill names and descriptions and explain when to call `skill_load`.
- Activation uses ordinary provider tool calls. No textual activation marker or additional inference protocol is introduced.
- The toolkit depends on `SkillRepositoryInterface`, not on a filesystem implementation.
- The repository interface exposes catalog discovery, full instruction loading, and resource loading by skill name and logical relative path.
- The initial framework implementation includes a filesystem repository and a composite repository.
- Tests may use an in-memory or fake repository, but database and HTTP repositories are not production implementations in this scope.
- The filesystem repository receives one explicit skills root and discovers direct child directories containing `SKILL.md`.
- The framework does not automatically inspect the current project, parent directories, user home, or conventional client-specific locations.
- The filesystem repository implements the Agent Skills directory and frontmatter format with a real YAML parser capable of handling the specification's metadata mapping.
- Discovery loads only the metadata required for the catalog. Complete instructions and resources are loaded lazily.
- Invalid required metadata is diagnosed during catalog construction. One invalid skill does not make unrelated valid skills unavailable, but its diagnostic remains observable to the application.
- A composite repository presents one catalog while retaining the owning repository for every skill.
- A complete skill belongs to exactly one repository. Instructions from one repository cannot be combined implicitly with resources from another.
- Duplicate names across repositories are configuration errors. The initial implementation has no implicit priority or shadowing behavior.
- `skill_load` accepts a registered skill name and returns structured textual instructions suitable for a tool result.
- `skill_load_resource` accepts a registered skill name and a logical relative path, then delegates to the repository that owns the skill.
- Skill names exposed to the model are constrained to the registered catalog when the existing tool property system can express the catalog as an enum.
- Both tools use input-sensitive run keys so distinct skill and resource requests are tracked separately.
- Filesystem resource resolution rejects empty paths, absolute paths, traversal outside the skill directory, and symlinks whose resolved target is outside that directory.
- The initial tool-result contract is textual. UTF-8 text resources are returned as content. Unsupported binary resources return descriptive metadata or a clear unsupported-content result rather than embedding arbitrary binary data in a string.
- The skill subsystem never executes scripts, performs HTTP requests described by a skill, instantiates PHP classes from skill content, starts queue jobs, or connects to MCP servers.
- Applications can register existing shell, filesystem, HTTP, PHP, queue, or MCP tools independently. Skill instructions may tell the model to use those capabilities when present.
- A loaded skill remains active for the current conversation and is deduplicated by name.
- Active skill instructions must survive Neuron's history trimming and summarization behavior. The implementation may track active skill names and reinject instructions or preserve marked skill results, but this mechanism remains internal to the module.
- Repository configuration consists of serializable values and dependencies suitable for Neuron AI 3 usage. Open streams and execution closures are not part of Skill values.
- The feature does not add `skills()` or `addSkill()` to Agent in the initial implementation. Agents opt in by registering the toolkit through the existing tools extension point.
- Public types and implementations remain compatible with PHP 8.1 and the repository's visibility and type-coverage rules.

## Testing Decisions

- Tests assert externally visible behavior rather than parser internals, caches, helper methods, or concrete directory layout inside the implementation.
- The primary test seam is SkillToolkit as consumed by an Agent. Tests observe the generated catalog, visible tools, tool inputs, tool results, deduplication, and behavior across the existing Agent tool loop.
- The second test seam is SkillRepositoryInterface. Tests observe catalog entries, loaded instructions, loaded resources, composition, diagnostics, and errors through the public repository contract.
- Existing toolkit tests provide prior art for verifying toolkit guidelines and exposed tools.
- Existing Agent tests and provider fakes provide prior art for exercising tool calls without contacting a real model provider.
- A tracer integration test starts with one valid filesystem skill, observes its catalog entry, loads it through `skill_load`, and reads one linked resource through `skill_load_resource`.
- Filesystem repository tests cover valid frontmatter, optional Agent Skills metadata, malformed YAML, missing required metadata, mismatched directory names, deterministic ordering, and isolation of invalid entries.
- Resource tests cover normal nested relative paths, missing resources, empty paths, absolute paths, `..` traversal, and symlinks resolving inside and outside the owning skill.
- Composite repository tests combine at least two repository implementations, verify that skills from both appear in one catalog, and verify that each load routes to the owning repository.
- Collision tests verify that duplicate names fail explicitly and do not depend on repository order.
- Tool tests verify input-sensitive run keys for different skill names and resource paths.
- Lifecycle tests verify that loading the same skill twice does not duplicate active instructions.
- Summarization tests verify that loaded instructions remain available after conversation summarization or trimming.
- Compatibility tests verify that registering the toolkit uses the same Agent extension point for chat, streaming, and structured output without specialized node changes.
- Unsupported binary resource tests verify a deterministic, model-readable result and no uncontrolled binary output.
- No test invokes a real shell, remote endpoint, database, queue, MCP server, or model provider.

## Out of Scope

- Neuron AI 4 support.
- Automatic discovery in project, ancestor, client-specific, or user-level directories.
- Installing, downloading, updating, or deleting skills.
- A public skill marketplace or registry service.
- Production database, object-storage, or HTTP repository implementations.
- Implicit repository precedence or silent skill shadowing.
- Combining one skill's instructions and resources from different repositories.
- Declarative `Tools` sections or any other proprietary extension to the `SKILL.md` body.
- HTTP, shell, PHP, queue, or MCP executors defined by skill content.
- Automatic execution of bundled scripts.
- Provider-specific approval or sandboxing systems.
- Full multimodal tool results for images, audio, PDF, or arbitrary binary assets.
- Recursive eager loading of references, assets, or scripts.
- Keyword-based skill activation or harness-side trigger matching.
- Custom textual activation markers.
- User-interface commands, mentions, autocomplete, or explicit user activation syntax.
- Skill dependency declarations or automatic dependency graphs.
- Skill installation trust policy beyond explicit repository registration and filesystem confinement.

## Further Notes

- Ligea provides working prior art for the central flow: parse Agent Skills from a configured root, add the metadata catalog to an Agent, load complete instructions through a normal tool, and read resources progressively. The reusable behavior should be generalized rather than copied with its Symfony service configuration or hard-coded project paths.
- Ligea's script execution capability is intentionally excluded. The installed Ligea skills exercise catalog, instruction, and reference loading, while providing no evidence that a core script executor is necessary.
- Neuron AI 3 currently represents tool results as strings. This is why the initial resource contract is textual and full multimodal assets remain outside this specification.
- Neuron AI 3 summarization currently discards detailed tool-result content. Skill instructions therefore need explicit lifecycle handling instead of relying solely on ordinary chat history.
- The existing open skills proposal remains useful as research, but its custom activation protocol, execution subsystem, and unrelated Agent refactors are not the implementation model for this specification.
