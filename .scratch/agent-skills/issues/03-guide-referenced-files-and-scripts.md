# 03: Guide referenced files and scripts

**What to build:** Extend the Skills toolkit guidelines so the model knows how to follow file references in loaded instructions and how scripts relate to application-provided execution tools.

**Blocked by:** 02: Read skill resources through the skill tool.

**Status:** resolved

- [x] The guidelines state that skill instructions may reference other files in the package.
- [x] The guidelines direct the model to load every referenced file by calling the same `skill` tool with its `path` input.
- [x] The guidelines direct the model, when a loaded file is a script and an appropriate execution tool is available, to use that separate tool to execute the loaded contents.
- [x] The wording makes clear that `skill` only reads text and never executes scripts itself.
- [x] Toolkit tests assert the complete guidance while preserving the existing catalog and empty-catalog behavior.
- [x] Relevant tests, formatting, static analysis, and PHP 8.1 compatibility checks pass.

## Answer

Extended the Skills toolkit guidelines to require every referenced package file to be loaded through `skill` with its `path` input. The guidance makes `skill` explicitly read-only and directs loaded script contents to a separate appropriate execution tool when one is available. Agent-level toolkit tests cover the complete guidance while retaining the existing catalog and empty-catalog behavior.
