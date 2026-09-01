# Agent Skills support for Neuron AI 3

Status: ready-for-agent

## Problem Statement

Neuron AI 3 can expose tools and toolkit guidelines to an Agent, but it has no reusable way to consume instruction packages built around `SKILL.md`. Applications currently have to discover skills, build a lightweight catalog, load instructions progressively, resolve supporting files, and secure filesystem access themselves.

The integration must fit Neuron's existing component model. A skill is contextual guidance, not a new execution subsystem, and loading one must not require changes to Agent, Workflow, inference nodes, chat history, or context-compaction internals. It must also avoid granting unrestricted filesystem access and avoid imposing a new package dependency on every Neuron installation.

## Solution

Add a small Skills toolkit backed by a storage-neutral repository interface. An application registers the toolkit with a repository of its choice. The toolkit contributes the available skill catalog to the Agent's existing toolkit guidelines and exposes one model-facing tool named `skill`.

Calling `skill` with a skill name returns that skill's Markdown instructions as an ordinary textual tool result. Supplying an optional relative path to the same tool returns one textual supporting file from that skill. The toolkit does not track active skills, deduplicate loads, reinject instructions, or alter history management. Skill content remains available only according to Neuron's normal conversation-history behavior.

The initial filesystem repository receives one explicit root, snapshots a deterministic catalog of valid direct-child skills, and reads instruction bodies and supporting files lazily. It accepts directory symlinks so the same package can be shared between conventional skill locations, while confining every subsequent read to the selected skill's resolved directory.

## User Stories

1. As a Neuron application developer, I want to register Agent Skills through a normal toolkit, so that skill support composes with the framework I already use.
2. As a Neuron application developer, I want to select the skill repository explicitly, so that the framework never searches locations I did not authorize.
3. As a Neuron application developer, I want filesystem storage hidden behind an interface, so that I can later supply skills from a database or remote service.
4. As a repository implementer, I want a small storage-neutral contract, so that custom storage does not depend on filesystem concepts.
5. As an Agent author, I want the toolkit to publish only skill names and descriptions initially, so that unused instructions do not consume context.
6. As an Agent author, I want the catalog placed in the toolkit's system guidelines, so that all existing Agent inference modes receive it through the normal bootstrap path.
7. As an Agent user, I want the model to see when each skill is relevant, so that it can select specialized instructions when needed.
8. As an Agent user, I want the model to load a skill through provider-native tool calling, so that activation does not depend on textual markers.
9. As an Agent user, I want one tool named `skill`, so that skill loading has a small and recognizable model-facing API.
10. As an Agent user, I want a skill name constrained to the published catalog, so that the model is less likely to request nonexistent skills.
11. As an Agent user, I want the complete Markdown body returned when the tool receives only a skill name, so that the model can follow the selected instructions.
12. As an Agent user, I want YAML frontmatter omitted from loaded instructions, so that catalog metadata is not repeated as operational guidance.
13. As a skill author, I want instructions loaded only when selected, so that the integration preserves progressive disclosure.
14. As a skill author, I want supporting files loaded only when requested, so that detailed references do not consume context prematurely.
15. As a skill author, I want the same `skill` tool to read instructions and supporting files, so that the model does not need separate activation and resource APIs.
16. As a skill author, I want supporting files addressed by logical paths relative to the skill root, so that a package remains portable across storage implementations.
17. As a skill author, I want arbitrary nested files inside the package to be readable, so that the framework does not hard-code `references`, `scripts`, or `assets` categories.
18. As an application developer, I want bundled scripts treated as text rather than executed automatically, so that installing a skill does not grant code-execution capability.
19. As a security-conscious application developer, I want absolute resource paths rejected, so that the skill tool cannot become a general filesystem reader.
20. As a security-conscious application developer, I want parent traversal rejected, so that a resource request cannot escape its skill package.
21. As a security-conscious application developer, I want resource symlinks confined to the resolved skill directory, so that symlinks cannot expose unrelated files.
22. As an application developer, I want directory symlinks to complete skill packages supported, so that one package can be linked between `.agents` and client-specific skill directories.
23. As an application developer, I want the resolved directory of a linked package to become its resource boundary, so that sharing and confinement work together.
24. As an application developer, I want only direct children of the configured root discovered, so that discovery remains bounded and predictable.
25. As an application developer, I want the frontmatter name to match the logical directory entry, so that model-facing identifiers remain deterministic even for linked packages.
26. As an application developer, I want catalog entries sorted deterministically, so that prompts and tests are stable.
27. As an application developer, I want malformed skills skipped silently, so that one bad package does not disable unrelated valid skills or require a diagnostics API.
28. As an application developer, I want an empty repository to contribute neither guidelines nor a tool, so that the model never sees an unusable skill capability.
29. As an application developer, I want the catalog fixed for the toolkit's lifetime, so that its guidelines and accepted tool names cannot diverge.
30. As a skill author, I want instruction and resource contents read at invocation time, so that progressive disclosure does not preload every package into memory.
31. As a skill author, I want edits made before a lazy read reflected in that read, so that content is not needlessly cached with the catalog metadata.
32. As an Agent user, I want missing skills and resources reported as readable tool results, so that the model can recover without terminating the Agent loop.
33. As a framework maintainer, I want unexpected programming and infrastructure failures to remain exceptions, so that genuine defects are not disguised as normal tool output.
34. As an Agent user, I want textual resources returned completely, so that instructions are not silently truncated.
35. As an Agent user, I want binary resources rejected with a clear message, so that arbitrary bytes are not embedded into a string tool result.
36. As a Neuron user who does not use skills, I want no additional runtime dependency, so that this optional feature does not increase my installation footprint.
37. As a framework maintainer, I want the implementation to follow existing toolkit organization, so that its supporting types remain easy to find.
38. As a framework maintainer, I want skill calls tracked by their inputs, so that requests for different skills or paths do not share one run key.
39. As a framework maintainer, I want the feature to work through the existing Agent tool loop, so that chat, streaming, and structured-output modes need no special branches.
40. As a framework maintainer, I want tests focused on the Agent/toolkit and repository contracts, so that private parsing and caching details can change safely.
41. As an Agent user, I want the toolkit guidelines to explain that skill instructions may reference package files, so that the model continues progressive disclosure correctly.
42. As an Agent user, I want referenced files loaded through the same `skill` tool and loaded script contents handed to an appropriate execution tool when one is available, so that reading remains confined while optional execution uses application-provided capabilities.
43. As a framework maintainer, I want the toolkit directory and namespace named `Skills`, so that the public API uses the concise domain name rather than the redundant `AgentSkills` name.

## Implementation Decisions

- The feature targets Neuron AI 3 and uses the existing toolkit, tool, and Agent bootstrap contracts.
- All feature types live together under `src/Tools/Toolkits/Skills` and the `NeuronAI\Tools\Toolkits\Skills` namespace, following the existing convention of keeping toolkit-specific helpers beside their toolkit.
- The initial public surface contains five types: a catalog-entry value object, a repository interface, a filesystem repository, a toolkit, and one tool.
- The catalog-entry value object is named `SkillCatalogEntry` and contains only `name` and `description`.
- The repository interface exposes a catalog and one textual read operation. The read operation accepts a skill name and an optional logical path. An omitted path means the main instruction body; a supplied path means a supporting resource.
- Repository implementations own discovery and storage configuration. The filesystem implementation receives a root directory; future database or remote implementations may receive their own storage-specific dependencies without changing the toolkit.
- The initial framework implementation includes only the filesystem repository. Repository composition and precedence are not introduced.
- The toolkit is named `SkillToolkit`. It depends only on the repository interface.
- The model-facing tool is named `skill`, and its implementation type is named `SkillTool`.
- The tool accepts required `name` and optional `path` inputs. The name schema is constrained to the catalog snapshot when at least one valid skill exists.
- The toolkit places the catalog and concise loading instructions in its existing toolkit guidelines. The catalog contains only name and description; complete bodies and resources are never included there.
- The toolkit guidelines state that skill instructions may reference other package files and that every referenced file must first be loaded by calling `skill` with `path`.
- When the loaded file is a script and an appropriate execution tool is available, the guidelines direct the model to use that separate tool to execute the loaded contents.
- The `skill` tool remains text-only and never executes scripts itself.
- When no valid skills exist, the toolkit returns no guidelines and provides no tools.
- Calling `skill` without `path` returns only the trimmed Markdown body after the frontmatter closing delimiter.
- Calling `skill` with `path` returns the complete textual contents of that logical resource.
- Expected selection and content errors become concise textual tool results so the model can recover. These include an unavailable skill, empty or invalid resource path, missing resource, directory instead of file, path escape, external resource symlink, unreadable textual content, and unsupported binary content.
- Unexpected programming or infrastructure failures are not converted into successful-looking results and continue through Neuron's ordinary exception handling.
- The tool uses an input-sensitive run key covering both the skill name and optional path.
- Loaded instructions are ordinary tool results. The feature introduces no active-skill state, lifecycle middleware, reinjection, deduplication, pruning exemption, or compaction guarantee.
- Repeated calls are allowed and produce repeated tool results according to the normal tool loop.
- No Agent, Workflow, inference-node, chat-history, summarization, or structured-output internals are modified.
- The filesystem repository receives one explicit root and discovers only direct child directory entries containing `SKILL.md`.
- Neuron does not automatically scan the current project, parent directories, home directory, `.agents`, `.claude`, or other conventional locations. Applications choose and pass the desired root.
- Discovery creates an alphabetically ordered snapshot of valid catalog entries and their resolved directories. The available names and descriptions remain fixed for the lifetime of that repository/toolkit instance.
- Instruction bodies and supporting resources are not stored in the snapshot. They are read from storage on every tool invocation, so changes made before a read are visible without refreshing the catalog.
- A filesystem skill may be a direct-child directory symlink whose target is outside the configured root. The link's direct-child name is its logical directory name, while its canonical target becomes the skill's physical boundary.
- `SKILL.md` must resolve to a regular file inside the skill's canonical directory. A manifest symlink that escapes that directory is not accepted.
- A resource path must be non-empty, relative, and free of parent-directory segments. Its canonical target must be a regular file inside the canonical skill directory.
- Resource symlinks are allowed only when their canonical target remains within the canonical skill directory.
- The tool reads text only. Content containing null bytes or invalid UTF-8 is treated as unsupported binary content.
- No skill-specific byte, line, or token limit is introduced. Text is returned in full, with no silent truncation and no offset/limit protocol.
- The filesystem repository uses a deliberately minimal frontmatter reader rather than a YAML parser. It examines only lines beginning in the first column with `name:` or `description:`, splits each recognized line at the first colon, trims the remainder, and ignores every other line.
- The minimal frontmatter reader does not interpret YAML quoting, block scalars, nested mappings, comments, optional fields, or other YAML semantics. The implementation must not describe itself as a YAML parser or claim complete YAML compatibility.
- A filesystem skill is catalogued only when the opening and closing frontmatter delimiters are present, both required values are non-empty single-line values, the name satisfies the Agent Skills naming constraints, the description length satisfies the Agent Skills constraint, and the name matches the logical direct-child directory name.
- Invalid filesystem skills are omitted silently. The repository interface has no diagnostics method.
- Optional frontmatter such as license, compatibility, metadata, allowed tools, and client-specific extensions is ignored.
- The feature adds no Composer package or PHP-extension dependency. In particular, it does not require a YAML parser.
- The skill subsystem never executes scripts, performs network requests, instantiates PHP classes described by content, starts queue jobs, or connects to MCP servers. Applications register such capabilities independently as ordinary Neuron tools.
- Public APIs remain compatible with PHP 8.1 and the repository's strict typing, visibility, formatting, static-analysis, and type-coverage standards.

## Testing Decisions

- Tests assert externally visible contracts and avoid assertions about private helpers, parsing steps, cache fields, or implementation-specific control flow.
- The highest test seam is `SkillToolkit` consumed by a real Agent using the existing fake provider. These tests observe the final system guidelines, visible tool schema, normal tool call/result flow, and absence of skill support when the catalog is empty.
- The second and only additional seam is `SkillRepositoryInterface`, exercised through `FileSystemSkillRepository`. These tests cover storage behavior that would be unnecessarily opaque or cumbersome to diagnose solely through a model interaction.
- Existing toolkit tests are prior art for testing guidelines and provided tools. Existing Agent and fake-provider tests are prior art for observing system instructions, registered tools, tool calls, and textual results without contacting a real model.
- Agent/toolkit tests verify that the catalog contains only deterministic name-description entries, that full bodies are absent from initial instructions, and that the sole model-facing operation is named `skill`.
- Agent/toolkit tests verify calls with only `name`, calls with `name` and `path`, body-only instruction results, raw textual resource results, expected error text, and input-sensitive run tracking.
- Agent/toolkit tests cover the empty-catalog case and confirm that neither guidelines nor the tool are registered.
- Agent/toolkit tests verify the resource-loading and script-execution guidance without registering or invoking a real execution tool.
- Repository tests cover a valid direct child, ignored non-skill files, ignored nested skills, deterministic alphabetical ordering, and a nonexistent or empty root.
- Frontmatter tests cover exact first-column matching, splitting at the first colon, descriptions containing additional colons, missing delimiters, missing or empty required fields, invalid names, overlong values, directory-name mismatch, indented lookalike keys, ignored optional metadata, and silent exclusion.
- Snapshot tests prove that adding or removing a skill after discovery does not change the catalog, while modifying an already discovered instruction body or resource before a later read changes the returned text.
- Symlink tests cover a complete skill directory linked outside the configured root, use of its logical link name, a manifest confined to the resolved package, an escaping manifest symlink, a confined resource symlink, and an escaping resource symlink.
- Resource tests cover nested relative files, empty paths, POSIX and Windows absolute paths, parent traversal, missing files, directories, unreadable files, textual scripts that are not executed, null bytes, invalid UTF-8, and full untruncated text.
- Error tests distinguish expected model-readable failures from unexpected exceptions.
- Tests do not invoke a real provider, shell command, script, database, network endpoint, queue, or MCP server.
- No tests are added for middleware ordering, active-skill state, history deduplication, summarization, trimming, or compaction because those behaviors are outside this feature.

## Out of Scope

- Changes to Agent, Workflow, inference nodes, chat history, summarization, trimming, or structured-output internals.
- Active-skill registries, lifecycle middleware, persistent activation, deduplication, pruning protection, or reinjection after compaction.
- A composite repository, multiple-root precedence, collision resolution across repositories, or implicit shadowing.
- Production database, object-storage, HTTP, or other remote repository implementations.
- Automatic discovery in project, ancestor, user, organization, `.agents`, `.claude`, or client-specific locations.
- File watching, explicit refresh, cache invalidation, or mutation of the catalog during a toolkit's lifetime.
- Complete YAML parsing or support for quoted scalars, block scalars, nested values, anchors, aliases, tags, or arbitrary valid YAML syntax.
- Interpretation or validation of optional Agent Skills metadata.
- A diagnostics collection, logger integration, warning event, or failure of the whole catalog because one skill is invalid.
- User-facing slash commands, `$skill` mentions, autocomplete, selectors, or explicit host-side activation.
- Automatic matching outside the model's normal reasoning over the catalog descriptions.
- Enumeration or eager loading of all supporting files.
- Skill installation, downloading, updating, removal, marketplace support, or package management.
- Automatic execution of scripts or declarative shell, HTTP, PHP, queue, database, or MCP executors.
- Skill-defined permission elevation, allowed-tool enforcement, trust prompts, or provider-specific approval systems.
- Binary and multimodal tool results for images, audio, PDFs, archives, or other non-text assets.
- Skill-specific content-size limits, truncation, pagination, offsets, or streaming reads.
- A mandatory dependency on a YAML or frontmatter parsing library.

## Further Notes

- The model-facing behavior intentionally follows the small OpenCode-style contract: advertise a catalog, load selected content into ordinary history, and rely on the normal conversation lifecycle rather than creating a durable active-skill subsystem.
- Pi demonstrates that normal textual tool results are sufficient while they remain in history. OpenCode and Claude Code use a tool named `skill` or `Skill`, which motivates the concise Neuron tool name.
- The Agent Skills specification requires relative references from the skill root. Neuron adds canonical-path confinement as a client security policy; the specification itself does not define traversal or symlink handling.
- The Agent Skills specification describes YAML frontmatter. The chosen minimal reader intentionally supports only the simple `name:` and `description:` form needed by this feature, so full format compatibility is not claimed.
- The explicit repository root is an application boundary, not a conventional-location discovery policy. Allowing a direct-child directory symlink is considered an explicit registration of its resolved package.
- Neuron currently represents ordinary tool results as strings and does not expose a distinct failed-result flag. Expected read failures are therefore returned as explanatory text so the model can continue, while unexpected failures remain exceptions.
