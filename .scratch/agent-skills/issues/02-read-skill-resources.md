# 02: Read skill resources through the skill tool

**What to build:** Extend the existing `skill` tool so the model can request one textual supporting file by logical path while every filesystem read remains confined to the selected skill package.

**Blocked by:** 01: Register and load a filesystem skill.

**Status:** resolved

- [x] The existing `skill` tool accepts an optional path while preserving the name-only instruction-loading behavior delivered by ticket 01.
- [x] The repository's single textual read operation accepts the same optional logical path, keeping filesystem details out of the toolkit and custom repository contract.
- [x] Supplying a valid nested relative path returns that file's complete textual contents as an ordinary tool result.
- [x] The implementation does not hard-code resource categories; references, scripts, assets, and other nested files are addressed uniformly.
- [x] Empty paths, POSIX absolute paths, Windows absolute paths, UNC paths, parent-directory segments, missing files, and directories produce deterministic model-readable error text.
- [x] The requested file is resolved canonically against the selected skill's real directory, and the read is rejected if the resolved target escapes that package.
- [x] Resource symlinks whose targets remain inside the canonical skill directory are readable, while symlinks escaping the package return a model-readable error.
- [x] UTF-8 text is returned in full without a skill-specific byte limit, truncation, pagination, or offset protocol.
- [x] Null bytes and invalid UTF-8 produce an unsupported-binary error rather than embedding arbitrary bytes in the tool result.
- [x] Bundled scripts can be read as text but are never executed by the Skills toolkit.
- [x] Expected selection, path, and content failures are returned as explanatory text so the model can recover; unexpected programming or infrastructure failures remain exceptions.
- [x] Tool run tracking includes both the selected skill name and optional path so distinct reads do not consume one shared run key.
- [x] Agent/toolkit tests exercise both name-only and name-plus-path calls through the normal tool loop, while repository tests cover traversal, canonical confinement, symlinks, textual scripts, binary content, and lazy resource edits.
- [x] No active-skill state, lifecycle middleware, content deduplication, history protection, or compaction behavior is introduced.
- [x] Relevant tests, formatting, static analysis, and type-coverage checks pass on supported PHP versions.

## Answer

Extended the existing storage-neutral read contract and `skill` tool with an optional logical path. The filesystem repository now validates and canonically confines resource reads, returns complete UTF-8 text, and reports expected path, selection, readability, and binary-content failures as model-readable results. Agent-loop and repository tests cover instruction and resource reads, run keys, traversal, symlinks, scripts, binary data, lazy edits, and untruncated content.
