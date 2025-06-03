<?php

namespace NeuronAI\Tests;

use NeuronAI\RAG\DataLoader\DocumentSplitter;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

class DocumentSplitterTest extends TestCase
{
    public function test_short_text_returns_single_chunk()
    {
        $text = "This is a short text.";
        $splitter = new DocumentSplitter(chunkSize: 100, chunkOverlap: 0, minChunkSize: 10);
        
        $chunks = $splitter->splitText($text);

        $this->assertCount(1, $chunks);
        $this->assertSame($text, $chunks[0]);
    }

    public function test_recursive_split_on_paragraphs_and_lines()
    {
        $text = "First paragraph with some words for testing\n".
                "\n".
                "Second paragraph a bit longer than before, containing more than twenty characters";

        $splitter = new DocumentSplitter(chunkSize: 30, chunkOverlap: 0, minChunkSize: 5);
        $chunks = $splitter->splitText($text);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(30, mb_strlen($chunk));
        }

        $this->assertGreaterThanOrEqual(2, count($chunks));
        
        $chunksWithFirstParagraph = array_filter($chunks, fn($chunk) => str_contains($chunk, "First paragraph"));
        $this->assertNotEmpty($chunksWithFirstParagraph, "Didn't find any chunk containing 'First paragraph'.");
    }

    public function test_merge_short_chunks()
    {
        $text = "AAA BBB CCC\n".
                "D E F G H I J K\n".
                "LMNOPQRSTUVWXYZ\n".
                "Z";

        $splitter = new DocumentSplitter(chunkSize: 20, minChunkSize: 5, chunkOverlap: 0);
        $chunks = $splitter->splitText($text);

        $this->assertCount(3, $chunks);
        $this->assertSame("AAA BBB CCC", $chunks[0]);
        $this->assertSame("D E F G H I J K", $chunks[1]);
        $this->assertSame("LMNOPQRSTUVWXYZ\nZ", $chunks[2]);
        $this->assertLessThanOrEqual(20, mb_strlen($chunks[2]));
    }

    public function test_apply_overlap()
    {
        $text = "ABCDEFGHIJKLMNOP\n".
                "\n".
                "1234567890ABCDEF";

        $splitter = new DocumentSplitter(chunkSize: 25, minChunkSize: 2, chunkOverlap: 4);
        $chunks = $splitter->splitText($text);

        $this->assertCount(2, $chunks);
        $this->assertSame("ABCDEFGHIJKLMNOP", $chunks[0]);
        $this->assertSame("MNOP 1234567890ABCDEF", $chunks[1]); // 4-char overlap
    }

    public function test_split_by_character_fallback()
    {
        $text = str_repeat("X", 25); // No separators

        $splitter = new DocumentSplitter(chunkSize: 10, minChunkSize: 1, chunkOverlap: 0);
        $chunks = $splitter->splitText($text);

        $this->assertCount(3, $chunks);
        $this->assertSame(str_repeat("X", 10), $chunks[0]);
        $this->assertSame(str_repeat("X", 10), $chunks[1]);
        $this->assertSame(str_repeat("X", 5), $chunks[2]);
    }

    public function test_language_specific_separators()
    {
        $htmlText = "<div><p>Paragraph one</p><p>Paragraph two</p></div>";

        $splitter = new DocumentSplitter(chunkSize: 20, minChunkSize: 5, chunkOverlap: 0);
        $splitter->forLanguage('html');

        $chunks = $splitter->splitText($htmlText);

        $this->assertGreaterThan(1, count($chunks));
        
        $chunksWithHtmlContent = array_filter($chunks, fn($chunk) => str_contains($chunk, '<') || str_contains($chunk, 'Paragraph'));
        $this->assertNotEmpty($chunksWithHtmlContent);
    }

    public function test_split_document()
    {
        $content = "This is a long document that will be divided into multiple parts to test the complete functionality.";
        
        $document = new Document($content);
        $document->sourceName = "test.txt";
        $document->sourceType = "text";

        $splitter = new DocumentSplitter(chunkSize: 30, minChunkSize: 5, chunkOverlap: 5);
        $splitDocuments = $splitter->splitDocument($document);

        $this->assertGreaterThan(1, count($splitDocuments));
        
        foreach ($splitDocuments as $doc) {
            $this->assertNotEmpty($doc->hash);
            $this->assertNotEmpty($doc->id);
            $this->assertSame("test.txt", $doc->sourceName);
            $this->assertSame("text", $doc->sourceType);
            $this->assertIsInt($doc->chunkNumber);
        }
    }

    public function test_split_long_text_file()
    {
        $content = file_get_contents(__DIR__.'/stubs/long-text.txt');
        
        $splitter = new DocumentSplitter(chunkSize: 500, minChunkSize: 50, chunkOverlap: 50);
        $chunks = $splitter->splitText($content);

        $this->assertGreaterThan(5, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(500 + 50, mb_strlen($chunk)); // chunkSize + potential overlap
        }
    }
}
