<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use Throwable;

/** A stream adapter that can render an unhandled workflow failure. */
interface ErrorAwareStreamAdapterInterface extends StreamAdapterInterface
{
    /**
     * Render the protocol-specific error termination.
     *
     * The original exception is rethrown after this output is consumed.
     *
     * @return iterable<string>
     */
    public function error(Throwable $exception): iterable;
}
