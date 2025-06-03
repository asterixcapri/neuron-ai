<?php

namespace NeuronAI\RAG\DataLoader;

abstract class AbstractDataLoader implements DataLoaderInterface
{
    protected int $chunkSize = 1000;
    protected int $chunkOverlap = 100;
    protected int $minChunkSize = 200;
    protected array $separators = ["\n\n", "\n", " ", ""];
    protected string $language = '';

    public static function for(...$arguments): static
    {
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    public function withChunkSize(int $chunkSize): DataLoaderInterface
    {
        $this->chunkSize = $chunkSize;
        return $this;
    }

    public function withChunkOverlap(int $chunkOverlap): DataLoaderInterface
    {
        $this->chunkOverlap = $chunkOverlap;
        return $this;
    }

    public function withMinChunkSize(int $minChunkSize): DataLoaderInterface
    {
        $this->minChunkSize = $minChunkSize;
        return $this;
    }

    public function withSeparators(array $separators): DataLoaderInterface
    {
        $this->separators = $separators;
        return $this;
    }

    public function forLanguage(string $language): DataLoaderInterface
    {
        $this->language = $language;
        return $this;
    }

    /**
     * @deprecated Use withChunkSize() instead
     */
    public function withMaxLength(int $maxLength): DataLoaderInterface
    {
        return $this->withChunkSize($maxLength);
    }

    /**
     * @deprecated Use withChunkOverlap() instead
     */
    public function withOverlap(int $overlap): DataLoaderInterface
    {
        return $this->withChunkOverlap($overlap);
    }

    /**
     * @deprecated Use withSeparators() instead
     */
    public function withSeparator(string $separator): DataLoaderInterface
    {
        return $this->withSeparators([$separator]);
    }
}
