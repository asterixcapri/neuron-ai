# 03: Guide referenced files and scripts

**What to build:** Extend the Skills toolkit guidelines so the model knows how to follow file references in loaded instructions and how scripts relate to application-provided execution tools.

**Blocked by:** 02: Read skill resources through the skill tool.

**Status:** claimed

- [ ] The guidelines state that skill instructions may reference other files in the package.
- [ ] The guidelines direct the model to load every referenced file by calling the same `skill` tool with its `path` input.
- [ ] The guidelines direct the model, when a loaded file is a script and an appropriate execution tool is available, to use that separate tool to execute the loaded contents.
- [ ] The wording makes clear that `skill` only reads text and never executes scripts itself.
- [ ] Toolkit tests assert the complete guidance while preserving the existing catalog and empty-catalog behavior.
- [ ] Relevant tests, formatting, static analysis, and PHP 8.1 compatibility checks pass.
