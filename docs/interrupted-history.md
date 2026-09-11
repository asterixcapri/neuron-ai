# Interrupted conversation history (3.x)

Applications that stop a conversation before an assistant answer can retain the
unanswered input without inventing an assistant message. The default
`HistoryTrimmer` recognizes `stop_reason=interrupted` at these boundaries:

- An ordinary `UserMessage` marked interrupted may be followed by a new user input.
- A `ToolCallMessage` marked interrupted, followed by its `ToolResultMessage`, may
  be followed by a new user input instead of an assistant continuation.

An assistant continuation remains valid in both cases. Unmarked conversations
retain the existing validation rules. The marker does not remove any message
from history or token accounting, and does not exempt orphan tool results from
validation. Normal context-window trimming still applies.

## Before any assistant text

```php
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;

$history = new InMemoryChatHistory();
$input = (new UserMessage('Original request'))
    ->addMetadata('stop_reason', 'interrupted');
$history->addMessage($input);
$history->addMessage(new UserMessage('Changed request'));
```

## After a completed tool batch

When constructing the history of a stopped turn, mark the tool call before
persisting it, then append the actual results for that batch:

```php
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

// $completedTools contains the batch's tools with their actual results.
$history = new InMemoryChatHistory();
$history->addMessage(new UserMessage('Run the tools'));
$call = (new ToolCallMessage(tools: $completedTools))
    ->setStopReason('interrupted');
$history->addMessage($call);
$history->addMessage(new ToolResultMessage($completedTools));
$history->addMessage(new UserMessage('Next request'));
```

These are separate examples. The batch marker belongs on the **call**, not the
result: Neuron 3.x restores call metadata when deserializing history. A partial
ordinary assistant answer can already use `setStopReason('interrupted')` without
requiring any special alternation rule.

## Scope and limits

This is a history-validation convention, not a cancellation API. It does not stop
an Agent, provider request, stream or tool, synthesize tool outcomes, or persist
mutations to messages already stored. Mark messages before adding them; updating
an existing stored record remains the application's responsibility.

Provider-specific requirements still apply to messages sent to a model. This
change accepts the history locally; it does not guarantee that every provider
accepts every interrupted sequence unchanged.
