<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Generator;
use NeuronAI\Chat\Messages\Stream\Adapters\ErrorAwareStreamAdapterInterface;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class WorkflowStreamTest extends TestCase
{
    public function testWorkflowStreaming(): void
    {
        $workflow = Workflow::make()->addNodes([
            new NodeOne(),
            new NodeTwo(),
            new NodeThree(),
        ]);

        $handler = $workflow->init();

        foreach ($handler->events() as $event) {
            $this->assertInstanceOf(Event::class, $event);
        }

        $finalState = $handler->run();
        $this->assertTrue($finalState->get('node_one_executed'));
    }

    public function testStreamingErrorsAreRenderedByTheAdapterAndRethrown(): void
    {
        $exception = new RuntimeException('Provider credentials must remain private.');
        $adapter = new RecordingErrorAwareStreamAdapter();
        $handler = new WorkflowHandler($this->failingWorkflow($exception));
        $output = [];

        try {
            foreach ($handler->events($adapter) as $event) {
                $output[] = $event;
            }

            $this->fail('The streaming exception was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(['start', 'event', 'error'], $output);
        $this->assertSame([$exception], $adapter->errors);
    }

    public function testWorkflowInterruptsAreNotRenderedAsErrors(): void
    {
        $interrupt = $this->createStub(WorkflowInterrupt::class);
        $adapter = new RecordingErrorAwareStreamAdapter();
        $handler = new WorkflowHandler($this->failingWorkflow($interrupt));
        $output = [];

        try {
            foreach ($handler->events($adapter) as $event) {
                $output[] = $event;
            }

            $this->fail('The workflow interrupt was not rethrown.');
        } catch (WorkflowInterrupt $caught) {
            $this->assertSame($interrupt, $caught);
        }

        $this->assertSame(['start', 'event'], $output);
        $this->assertCount(0, $adapter->errors);
    }

    private function failingWorkflow(Throwable $exception): Workflow
    {
        return new class ($exception) extends Workflow {
            public function __construct(protected Throwable $exception)
            {
                parent::__construct();
            }

            public function run(): Generator
            {
                yield new StartEvent();

                throw $this->exception;
            }
        };
    }
}

final class RecordingErrorAwareStreamAdapter implements ErrorAwareStreamAdapterInterface
{
    /** @var list<Throwable> */
    public array $errors = [];

    public function transform(object $chunk): iterable
    {
        yield 'event';
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function start(): iterable
    {
        yield 'start';
    }

    public function end(): iterable
    {
        yield 'end';
    }

    public function error(Throwable $exception): iterable
    {
        $this->errors[] = $exception;

        yield 'error';
    }
}
