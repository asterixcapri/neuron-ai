# 02: Read skill resources through the skill tool

**What to build:** Extend the existing `skill` tool so the model can request one textual supporting file by logical path while every filesystem read remains confined to the selected skill package.

**Blocked by:** 01: Register and load a filesystem skill.

**Status:** ready-for-agent

- [ ] The existing `skill` tool accepts an optional path while preserving the name-only instruction-loading behavior delivered by ticket 01.
- [ ] The repository's single textual read operation accepts the same optional logical path, keeping filesystem details out of the toolkit and custom repository contract.
- [ ] Supplying a valid nested relative path returns that file's complete textual contents as an ordinary tool result.
- [ ] The implementation does not hard-code resource categories; references, scripts, assets, and other nested files are addressed uniformly.
- [ ] Empty paths, POSIX absolute paths, Windows absolute paths, UNC paths, parent-directory segments, missing files, and directories produce deterministic model-readable error text.
- [ ] The requested file is resolved canonically against the selected skill's real directory, and the read is rejected if the resolved target escapes that package.
- [ ] Resource symlinks whose targets remain inside the canonical skill directory are readable, while symlinks escaping the package return a model-readable error.
- [ ] UTF-8 text is returned in full without a skill-specific byte limit, truncation, pagination, or offset protocol.
- [ ] Null bytes and invalid UTF-8 produce an unsupported-binary error rather than embedding arbitrary bytes in the tool result.
- [ ] Bundled scripts can be read as text but are never executed by the Skills toolkit.
- [ ] Expected selection, path, and content failures are returned as explanatory text so the model can recover; unexpected programming or infrastructure failures remain exceptions.
- [ ] Tool run tracking includes both the selected skill name and optional path so distinct reads do not consume one shared run key.
- [ ] Agent/toolkit tests exercise both name-only and name-plus-path calls through the normal tool loop, while repository tests cover traversal, canonical confinement, symlinks, textual scripts, binary content, and lazy resource edits.
- [ ] No active-skill state, lifecycle middleware, content deduplication, history protection, or compaction behavior is introduced.
- [ ] Relevant tests, formatting, static analysis, and type-coverage checks pass on supported PHP versions.
