<?php

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\RAG\Document;

class StringDataLoader extends AbstractDataLoader
{
    public function __construct(protected string $content)
    {
    }

    public function getDocuments(): array
    {
        $splitter = new DocumentSplitter(
            chunkSize: $this->chunkSize,
            chunkOverlap: $this->chunkOverlap,
            minChunkSize: $this->minChunkSize
        );

        $splitter->withSeparators($this->separators);
        $splitter->forLanguage($this->language);

        return $splitter->splitDocument(new Document($this->content));
    }
}
