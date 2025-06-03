<?php

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\RAG\Document;

/**
 * DocumentSplitter
 *
 * Implementation inspired by LangChain's RecursiveCharacterTextSplitter.
 *
 * Three-phase text splitting process:
 *
 * 1. Hierarchical Split: Tries to split on paragraphs first, then lines, then
 *    words, and finally characters until pieces are small enough
 *
 * 2. Merge Short Chunks: When a piece is too small, tries to combine it with
 *    the previous piece if they fit together
 *
 * 3. Apply Overlap: Takes some words from the end of each piece and adds them
 *    to the beginning of the next piece for context.
 *
 * Result: well-balanced chunks with semantic coherence and contextual continuity.
 *
 * Parameters:
 * - chunkSize: maximum characters per chunk
 * - chunkOverlap: characters to overlap between chunks
 * - minChunkSize: minimum chunk size before merging
 * - separators: ["\n\n", "\n", " ", ""] representing paragraphs, lines, words, characters
 */
class DocumentSplitter
{
    /**
     * @var int Maximum number of characters (UTF-8) per chunk
     */
    private int $chunkSize;

    /**
     * @var int Number of overlapping characters between chunks
     */
    private int $chunkOverlap;

    /**
     * @var int Minimum threshold (in characters): if a chunk is shorter,
     *          try to merge it with the previous one
     */
    private int $minChunkSize;

    /**
     * @var array<string> List of separators in order
     *                    paragraphs → lines → words → characters
     */
    private array $separators = ["\n\n", "\n", " ", ""];

    public function __construct(int $chunkSize = 1000, int $chunkOverlap = 100, int $minChunkSize = 200)
    {
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
        $this->minChunkSize = $minChunkSize;
    }

    /**
     * @param string[] $seps
     * @return $this
     */
    public function withSeparators(array $seps): static
    {
        $this->separators = $seps;
        return $this;
    }

    public function forLanguage(string $language): static
    {
        $this->separators = match($language) {
            'chinese', 'japanese' => [
                "\n\n",
                "\n",
                "。", // Ideographic full stop (ESSENTIAL - no spaces in Chinese)
                "！", // Ideographic exclamation
                "？", // Ideographic question
                "．", // Unicode fullwidth full stop
                "，", // Unicode fullwidth comma
                "、", // Ideographic comma
                "\u{200b}", // Zero-width space
                ""
            ],
            'html' => [
                "<body",
                "<div",
                "<p",
                "<br",
                "<li",
                "<h1",
                "<h2",
                "<h3",
                "<h4",
                "<h5",
                "<h6",
                "<span",
                "<table",
                "<tr",
                "<td",
                "<th",
                "<ul",
                "<ol",
                "<header",
                "<footer",
                "<nav",
                "<head",
                "<style",
                "<script",
                "<meta",
                "<title",
                "\n\n",
                "\n",
                " ",
                ""
            ],
            'markdown' => [
                "\n# ",
                "\n## ",
                "\n### ",
                "\n#### ",
                "\n##### ",
                "\n###### ",
                "```\n",
                "\n***\n",
                "\n---\n",
                "\n___\n",
                "\n\n",
                "\n",
                " ",
                ""
            ],
            default => [
                "\n\n",
                "\n",
                " ",
                ""
            ]
        };

        return $this;
    }

    public function splitDocument(Document $document): array
    {
        $text = $document->content;

        if (empty($text)) {
            return [];
        }

        if (\mb_strlen($text) <= $this->chunkSize) {
            if (empty($document->hash)) {
                $document->hash = \hash('sha256', $text);
            }

            if (empty($document->id)) {
                $document->id = \uniqid();
            }

            return [$document];
        }

        $chunks = $this->splitText($text);

        $documents = [];
        $chunkNumber = 0;

        foreach ($chunks as $chunk) {
            $newDocument = new Document($chunk);
            $newDocument->hash = \hash('sha256', $chunk);
            $newDocument->id = \uniqid();
            $newDocument->sourceType = $document->sourceType;
            $newDocument->sourceName = $document->sourceName;
            $newDocument->chunkNumber = $chunkNumber;
            $chunkNumber++;

            $documents[] = $newDocument;
        }

        return $documents;
    }

    public function splitDocuments(array $documents): array
    {
        return array_map(fn($document) => $this->splitDocument($document), $documents);
    }

    /**
     * Split the entire text into chunks ≤ chunkSize (before overlap),
     * then apply merge of chunks that are too short and finally (optional)
     * add overlap for context.
     *
     * @param string $text Text to split
     * @return string[]    Array of chunks (with possible overlap)
     */
    public function splitText(string $text): array
    {
        $normalized = \str_replace("\r\n", "\n", $text);
        $normalized = \trim($normalized);

        if (\mb_strlen($normalized) === 0) {
            return [];
        }

        if (\mb_strlen($normalized) <= $this->chunkSize) {
            return [$normalized];
        }

        $rawChunks = $this->splitRecursively($normalized, $this->separators);

        // Remove any empty fragments or fragments composed only of whitespace
        $filtered = array_filter($rawChunks, function(string $c) {
            return \mb_strlen(\trim($c)) > 0;
        });

        $filtered = array_values($filtered);

        $merged = $this->mergeShortChunks($filtered);

        if ($this->chunkOverlap > 0) {
            return $this->applyOverlap($merged);
        }

        return $merged;
    }

    /**
     * Recursive function that attempts to split $text using one separator at a time.
     *
     * @param string   $text       Current text to split
     * @param string[] $separators List of remaining separators (in hierarchical order)
     * @return string[]            "Partial" array of chunks (each ≤ chunkSize)
     */
    private function splitRecursively(string $text, array $separators): array
    {
        $text = \trim($text);
        $length = \mb_strlen($text);

        // if already ≤ chunkSize, return as single array
        if ($length <= $this->chunkSize) {
            return [$text];
        }

        // if only empty separator remains, fallback to splitByCharacter
        if (\count($separators) === 1 && $separators[0] === "") {
            return $this->splitByCharacter($text);
        }

        // Take current separator and prepare remaining ones
        $currentSeparator = array_shift($separators);

        // If separator is not empty and appears in text, perform explode
        if ($currentSeparator !== "" && \mb_strpos($text, $currentSeparator) !== false) {
            $parts = \explode($currentSeparator, $text);
            $chunks = [];
            $buffer = "";

            foreach ($parts as $part) {
                $part = \trim($part);

                if ($part === "") {
                    continue;
                }

                $sepLen = \mb_strlen($currentSeparator);
                $bufferLen = \mb_strlen($buffer);
                $partLen = \mb_strlen($part);

                $combinedLen = $bufferLen + ($bufferLen > 0 ? $sepLen : 0) + $partLen;

                // If concatenation buffer + sep + part fits within chunkSize, do it
                if ($combinedLen <= $this->chunkSize) {
                    if ($bufferLen > 0) {
                        $buffer .= $currentSeparator;
                    }

                    $buffer .= $part;
                } else {
                    // Otherwise "close" current buffer (if not empty)
                    if ($bufferLen > 0) {
                        $chunks[] = $buffer;
                    }

                    // If part is still longer than chunkSize, recurse
                    if ($partLen > $this->chunkSize) {
                        $subChunks = $this->splitRecursively($part, $separators);

                        foreach ($subChunks as $sub) {
                            $chunks[] = $sub;
                        }

                        $buffer = "";
                    } else {
                        // Otherwise part becomes the new buffer
                        $buffer = $part;
                    }
                }
            }

            // At the end of foreach, if something remains in buffer, add it
            if (\mb_strlen($buffer) > 0) {
                $chunks[] = $buffer;
            }

            return $chunks;
        }

        // If current separator is not found in text (or is empty), go to next level
        return $this->splitRecursively($text, $separators);
    }

    /**
     * Split fallback: cut text into blocks of chunkSize characters,
     * without respecting semantic boundaries.
     *
     * @param string $text Text to split "bluntly"
     * @return string[]    Array of substrings (each ≤ chunkSize)
     */
    private function splitByCharacter(string $text): array
    {
        $chunks = [];
        $pos = 0;
        $len = \mb_strlen($text);

        while ($pos < $len) {
            $slice = \mb_substr($text, $pos, $this->chunkSize);
            $chunks[] = $slice;
            $pos += $this->chunkSize;
        }

        return $chunks;
    }

    /**
     * Merge chunks that are shorter than minChunkSize with the previous fragment,
     * as long as the combined length remains ≤ chunkSize. Otherwise leave them separate.
     *
     * @param string[] $chunks Array of "raw" chunks
     * @return string[]        Array of "merged" chunks (each ≤ chunkSize)
     */
    private function mergeShortChunks(array $chunks): array
    {
        $merged = [];

        foreach ($chunks as $chunk) {
            $chunkLen = \mb_strlen($chunk);

            if ($chunkLen < $this->minChunkSize && \count($merged) > 0) {
                // Try to merge with the last chunk already saved
                $last = array_pop($merged);
                $combined = $last." ".$chunk;

                if (\mb_strlen($combined) <= $this->chunkSize) {
                    $merged[] = $combined;
                    continue;
                } else {
                    // If union exceeds chunkSize, keep both separate
                    $merged[] = $last;
                    $merged[] = $chunk;
                    continue;
                }
            }

            // If chunk is already ≥ minChunkSize, or there's no previous: save it as is
            $merged[] = $chunk;
        }

        return $merged;
    }

    /**
     * Apply overlap between adjacent chunks by prepending text from the previous chunk.
     *
     * @param string[] $chunks Array of chunks (each ≤ chunkSize)
     * @return string[]        Array of chunks with overlap (potentially > chunkSize)
     */
    private function applyOverlap(array $chunks): array
    {
        if (\count($chunks) <= 1 || $this->chunkOverlap <= 0) {
            return $chunks;
        }

        $result = [];

        for ($i = 0; $i < \count($chunks); $i++) {
            $curr = $chunks[$i];

            if ($i === 0) {
                // First chunk has no "previous"
                $result[] = $curr;
                continue;
            }

            $prev = $chunks[$i - 1];
            $prevLen = \mb_strlen($prev);
            $currLen = \mb_strlen($curr);

            $effectiveOverlap = min(
                $this->chunkOverlap,
                (int) floor($prevLen / 4),
                (int) floor($currLen / 4)
            );

            if ($effectiveOverlap > 0) {
                $overlapText = \mb_substr($prev, $prevLen - $effectiveOverlap, $effectiveOverlap);
                // Prepend overlap + space + current chunk
                $curr = \trim($overlapText)." ".$curr;
            }

            $result[] = $curr;
        }

        return $result;
    }
}
