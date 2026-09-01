# 04: Preserve loaded skills throughout the conversation

**What to build:** Keep loaded skill instructions effective for the whole Agent conversation, including after history trimming or summarization, without duplicating instructions or introducing a parallel activation protocol.

**Blocked by:** 01: Load an Agent Skill from filesystem.

**Status:** ready-for-agent

- [ ] A successful `skill_load` records that the skill is active for the current conversation.
- [ ] Loading an already active skill does not inject a second copy of its instructions.
- [ ] Active skill instructions remain available after history trimming removes the original tool result.
- [ ] Active skill instructions remain available after summarization replaces older conversation content.
- [ ] Active state is scoped to the relevant Agent conversation and does not leak into unrelated conversations.
- [ ] The lifecycle uses ordinary tool calls and results without textual activation markers or a second inference state machine.
- [ ] The implementation remains compatible with Neuron AI 3 chat, streaming, and structured-output behavior.
- [ ] Tests prove deduplication and preservation through the Agent-facing seam using existing history and provider fakes.
