# 04: Preserve loaded skills throughout the conversation

**What to build:** Keep loaded skill instructions effective for the whole Agent conversation, including after history trimming or summarization, without duplicating instructions or introducing a parallel activation protocol.

**Blocked by:** 01: Load an Agent Skill from filesystem.

**Status:** resolved

- [x] A successful `skill_load` records that the skill is active for the current conversation.
- [x] Loading an already active skill does not inject a second copy of its instructions.
- [x] Active skill instructions remain available after history trimming removes the original tool result.
- [x] Active skill instructions remain available after summarization replaces older conversation content.
- [x] Active state is scoped to the relevant Agent conversation and does not leak into unrelated conversations.
- [x] The lifecycle uses ordinary tool calls and results without textual activation markers or a second inference state machine.
- [x] The implementation remains compatible with Neuron AI 3 chat, streaming, and structured-output behavior.
- [x] Tests prove deduplication and preservation through the Agent-facing seam using existing history and provider fakes.

## Answer

Successful instruction-providing tools now register a serializable contribution in `AgentState`, scoped to the current Agent conversation. Inference nodes append active instructions only when the matching ordinary tool result is no longer present in the conversation sent to the provider. Repeated loads return a short already-active result instead of copying the full instructions again.

The shared inference behavior covers chat, streaming, and structured output. Agent-facing tests verify deduplication, isolation between Agents, preservation after real history trimming and summarization, and compatibility across all three modes.
