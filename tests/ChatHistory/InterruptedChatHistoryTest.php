<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function count;
use function random_bytes;
use function sys_get_temp_dir;

class InterruptedChatHistoryTest extends TestCase
{
    /** @return iterable<string, array{list<Message>}> */
    public static function validSequences(): iterable
    {
        yield 'unanswered input then new turn' => [[self::interruptedUser(), new UserMessage('Next'), new AssistantMessage('Answer')]];
        yield 'repeated interrupted inputs' => [[self::interruptedUser(), self::interruptedUser(), new UserMessage('Next')]];
        yield 'input still allows assistant continuation' => [[self::interruptedUser(), new AssistantMessage('Answer')]];
        yield 'completed tools then new turn' => [[...self::interruptedTools(), new UserMessage('Next'), new AssistantMessage('Answer')]];
        yield 'tools still allow assistant continuation' => [[...self::interruptedTools(), new AssistantMessage('Answer')]];
        yield 'partial answer then new turn' => [[new UserMessage('Question'), (new AssistantMessage('Partial'))->setStopReason('interrupted'), new UserMessage('Next')]];
    }

    /** @param list<Message> $messages */
    #[DataProvider('validSequences')]
    public function test_interrupted_sequences_retain_all_messages_and_tokens(array $messages): void
    {
        $trimmer = new HistoryTrimmer();
        self::assertSame($messages, $trimmer->trim($messages, 50000));
        $counter = new TokenCounter();
        $tokens = 0;
        foreach ($messages as $message) {
            $tokens += $counter->count($message);
        }
        self::assertSame($tokens, $trimmer->getTotalTokens());

        $history = new InMemoryChatHistory();
        foreach ($messages as $message) {
            $history->addMessage($message);
        }
        self::assertSame($messages, $history->getMessages());
        self::assertSame($tokens, $history->calculateTotalUsage());
    }

    /** @return iterable<string, array{list<Message>, string}> */
    public static function invalidSequences(): iterable
    {
        yield 'unmarked consecutive inputs' => [[new UserMessage('First'), new UserMessage('Next')], 'expected role assistant, got user'];
        yield 'different stop reason' => [[(new UserMessage('First'))->addMetadata('stop_reason', 'stop'), new UserMessage('Next')], 'expected role assistant, got user'];
        yield 'marker only authorizes one transition' => [[self::interruptedUser(), new UserMessage('Next'), new UserMessage('Another')], 'expected role assistant, got user'];
        yield 'assistant marker does not allow two assistants' => [[new UserMessage('First'), (new AssistantMessage('Partial'))->setStopReason('interrupted'), new AssistantMessage('Another')], 'expected role user, got assistant'];
        yield 'orphan result after marked input' => [[self::interruptedUser(), new ToolResultMessage([])], 'must follow a ToolCallMessage'];
        yield 'marked tool call with wrong role' => [[new UserMessage('First'), (new ToolCallMessage())->setStopReason('interrupted')->setRole(MessageRole::USER)], 'must have ASSISTANT role'];
        yield 'unmarked completed tools' => [[new UserMessage('First'), new ToolCallMessage(), new ToolResultMessage([]), new UserMessage('Next')], 'expected role assistant, got user'];
        yield 'result marker is not a call marker' => [[new UserMessage('First'), new ToolCallMessage(), (new ToolResultMessage([]))->addMetadata('stop_reason', 'interrupted'), new UserMessage('Next')], 'expected role assistant, got user'];
        yield 'marker does not leak into a new batch' => [[...self::interruptedTools(), new ToolCallMessage(), new ToolResultMessage([]), new UserMessage('Next')], 'expected role assistant, got user'];
    }

    /** @param list<Message> $messages */
    #[DataProvider('invalidSequences')]
    public function test_other_invalid_sequences_are_still_rejected(array $messages, string $error): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage($error);
        (new HistoryTrimmer())->trim($messages, 50000);
    }

    /** @return iterable<string, array{list<Message>}> */
    public static function persistedBoundaries(): iterable
    {
        yield 'unanswered input' => [[self::interruptedUser()]];
        yield 'completed tools' => [self::interruptedTools()];
    }

    /** @param list<Message> $messages */
    #[DataProvider('persistedBoundaries')]
    public function test_boundary_survives_file_reload_and_accepts_a_new_turn(array $messages): void
    {
        $key = 'interrupted-'.bin2hex(random_bytes(8));
        $history = new FileChatHistory(sys_get_temp_dir(), $key);
        try {
            foreach ($messages as $message) {
                $history->addMessage($message);
            }
            $reloaded = new FileChatHistory(sys_get_temp_dir(), $key);
            $this->assertPersistedBoundary($messages, $reloaded->getMessages());
            $reloaded->addMessage(new UserMessage('Next'));
            $reloaded->addMessage(new AssistantMessage('Answer'));
            self::assertCount(count($messages) + 2, $reloaded->getMessages());
            $again = new FileChatHistory(sys_get_temp_dir(), $key);
            $this->assertPersistedBoundary($reloaded->getMessages(), $again->getMessages());
        } finally {
            $history->flushAll();
        }
    }

    /** @param list<Message> $messages */
    #[DataProvider('persistedBoundaries')]
    public function test_trimming_preserves_the_interrupted_boundary(array $messages): void
    {
        $older = [new UserMessage('Old'), (new AssistantMessage('Old answer'))->setUsage(new Usage(80, 20))];
        $tail = [...$messages, new UserMessage('Next'), new AssistantMessage('Answer')];
        $counter = new TokenCounter();
        $budget = 0;
        foreach ($tail as $message) {
            $budget += $counter->count($message);
        }
        $trimmer = new HistoryTrimmer();
        self::assertSame($tail, $trimmer->trim([...$older, ...$tail], $budget));
        self::assertSame($budget, $trimmer->getTotalTokens());
    }

    protected static function interruptedUser(): UserMessage
    {
        $message = new UserMessage('Interrupted input');
        $message->addMetadata('stop_reason', 'interrupted');
        return $message;
    }

    /**
     * @param list<Message> $expected
     * @param Message[] $actual
     */
    protected function assertPersistedBoundary(array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual);
        foreach ($expected as $index => $message) {
            self::assertInstanceOf($message::class, $actual[$index]);
            self::assertSame($message->getContent(), $actual[$index]->getContent());
            self::assertSame($message->getMetadata('stop_reason'), $actual[$index]->getMetadata('stop_reason'));
            if ($message instanceof ToolResultMessage) {
                self::assertInstanceOf(ToolResultMessage::class, $actual[$index]);
                self::assertEquals($message->getTools()[0]->jsonSerialize(), $actual[$index]->getTools()[0]->jsonSerialize());
            }
        }
    }

    /** @return list<Message> */
    protected static function interruptedTools(): array
    {
        $tool = (new Tool('lookup'))->setCallId('lookup-1')->setInputs(['query' => 'record'])->setResult('Found');
        return [
            new UserMessage('Find the record'),
            (new ToolCallMessage(tools: [$tool]))->setStopReason('interrupted'),
            new ToolResultMessage([$tool]),
        ];
    }
}
