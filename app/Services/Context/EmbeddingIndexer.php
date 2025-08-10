<?php

declare(strict_types=1);

namespace App\Services\Context;

use App\Contracts\EmbeddingProviderContract;
use App\DTO\EmbeddingSearchHitDto;
use App\DTO\VectorDto;
use App\Exceptions\EmbeddingGenerationException;
use App\Exceptions\SearchFailedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Service for indexing code with embeddings and searching.
 * Chunks source files AST-aware and stores vectors in pgvector.
 */
class EmbeddingIndexer
{
    /**
     * Maximum chunk size in characters.
     */
    private const MAX_CHUNK_SIZE = 1500;

    /**
     * Overlap between chunks in characters.
     */
    private const CHUNK_OVERLAP = 200;

    /**
     * Supported file extensions and their languages.
     */
    private const FILE_EXTENSIONS = [
        'php' => 'php',
        'js' => 'javascript',
        'jsx' => 'javascript',
        'ts' => 'typescript',
        'tsx' => 'typescript',
        'py' => 'python',
        'go' => 'go',
        'java' => 'java',
        'cs' => 'csharp',
        'rb' => 'ruby',
        'rs' => 'rust',
        'sql' => 'sql',
        'md' => 'markdown',
        'yml' => 'yaml',
        'yaml' => 'yaml',
        'json' => 'json',
        'xml' => 'xml',
        'html' => 'html',
        'css' => 'css',
        'scss' => 'scss',
    ];

    public function __construct(
        private readonly EmbeddingProviderContract $embeddingProvider,
    ) {}

    /**
     * Index a repository by creating embeddings for all files.
     *
     * @param  array<string>  $allowedPaths  Paths to include
     * @return int Number of chunks indexed
     *
     * @throws EmbeddingGenerationException
     */
    public function indexRepository(
        string $repoPath,
        int $projectId,
        array $allowedPaths = [],
    ): int {
        Log::info('Starting repository indexing', [
            'repo_path' => $repoPath,
            'project_id' => $projectId,
            'allowed_paths' => $allowedPaths,
        ]);

        // Find all indexable files
        $files = $this->findIndexableFiles($repoPath, $allowedPaths);

        $totalChunks = 0;

        foreach ($files as $file) {
            try {
                $chunks = $this->indexFile($file, $repoPath, $projectId);
                $totalChunks += $chunks;

                Log::debug('Indexed file', [
                    'file' => $file,
                    'chunks' => $chunks,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to index file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other files
            }
        }

        Log::info('Repository indexing completed', [
            'total_files' => count($files),
            'total_chunks' => $totalChunks,
        ]);

        return $totalChunks;
    }

    /**
     * Index a single file.
     *
     * @return int Number of chunks created
     */
    public function indexFile(string $filePath, string $repoPath, int $projectId): int
    {
        $content = File::get($filePath);
        $relativePath = str_replace($repoPath.'/', '', $filePath);
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $language = self::FILE_EXTENSIONS[$extension] ?? 'text';

        // Chunk the file based on language
        $chunks = match ($language) {
            'php' => $this->chunkPhpFile($content, $relativePath),
            'javascript', 'typescript' => $this->chunkJsFile($content, $relativePath),
            'python' => $this->chunkPythonFile($content, $relativePath),
            default => $this->chunkTextFile($content, $relativePath),
        };

        if (empty($chunks)) {
            return 0;
        }

        // Generate embeddings
        $vectors = $this->embeddingProvider->embedChunks($chunks);

        // Store in database
        foreach ($vectors as $index => $vector) {
            $this->storeEmbedding(
                projectId: $projectId,
                filePath: $relativePath,
                content: $chunks[$index],
                vector: $vector,
                language: $language,
                metadata: [
                    'chunk_index' => $index,
                    'total_chunks' => count($chunks),
                    'file_size' => strlen($content),
                ],
            );
        }

        return count($chunks);
    }

    /**
     * Search for similar code snippets.
     *
     * @return array<EmbeddingSearchHitDto>
     *
     * @throws SearchFailedException
     */
    public function search(string $query, int $k = 20, ?int $projectId = null): array
    {
        try {
            // Use the embedding provider's search method
            $hits = $this->embeddingProvider->search($query, $k);

            // Filter by project if specified
            if ($projectId !== null) {
                $hits = array_filter(
                    $hits,
                    fn (EmbeddingSearchHitDto $hit) => ($hit->metadata['project_id'] ?? null) === $projectId
                );
            }

            return array_values($hits);
        } catch (\Exception $e) {
            Log::error('Search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            throw new SearchFailedException(
                "Failed to search embeddings: {$e->getMessage()}"
            );
        }
    }

    /**
     * Chunk PHP file using AST.
     *
     * @return array<string>
     */
    private function chunkPhpFile(string $content, string $filePath): array
    {
        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse($content);

            if ($ast === null) {
                return $this->chunkTextFile($content, $filePath);
            }

            // Use name resolver to get fully qualified names
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $ast = $traverser->traverse($ast);

            $chunks = [];
            $currentChunk = "File: {$filePath}\n\n";

            // Get file-level docblock and namespace
            $lines = explode("\n", $content);
            $headerLines = array_slice($lines, 0, min(20, count($lines)));
            $header = implode("\n", $headerLines);

            if (str_contains($header, 'namespace ') || str_contains($header, '<?php')) {
                $currentChunk .= $header."\n\n";
            }

            // Extract classes, methods, and functions
            $visitor = new PhpChunkVisitor($content);
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->getChunks() as $chunk) {
                if (strlen($chunk) > self::MAX_CHUNK_SIZE) {
                    // Split large chunks
                    $subChunks = $this->splitLargeChunk($chunk);
                    $chunks = array_merge($chunks, $subChunks);
                } else {
                    $chunks[] = $chunk;
                }
            }

            // If no specific chunks found, fall back to text chunking
            if (empty($chunks)) {
                return $this->chunkTextFile($content, $filePath);
            }

            return $chunks;
        } catch (Error $e) {
            Log::warning('PHP parsing failed, falling back to text chunking', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return $this->chunkTextFile($content, $filePath);
        }
    }

    /**
     * Chunk JavaScript/TypeScript file.
     * For now, uses text-based chunking with some heuristics.
     *
     * @return array<string>
     */
    private function chunkJsFile(string $content, string $filePath): array
    {
        $chunks = [];
        $lines = explode("\n", $content);
        $currentChunk = "File: {$filePath}\n\n";
        $currentFunction = '';
        $braceLevel = 0;
        $inFunction = false;

        foreach ($lines as $line) {
            // Detect function/method/class starts
            if (preg_match('/^\s*(export\s+)?(async\s+)?(function|class|const|let|var)\s+(\w+)/', $line, $matches)) {
                if ($inFunction && ! empty($currentFunction)) {
                    // Save previous function
                    $chunks[] = $currentChunk.$currentFunction;
                    $currentFunction = '';
                }
                $inFunction = true;
            }

            $currentFunction .= $line."\n";

            // Track brace levels
            $braceLevel += substr_count($line, '{') - substr_count($line, '}');

            // End of function/block
            if ($inFunction && $braceLevel === 0 && str_contains($line, '}')) {
                $chunks[] = $currentChunk.$currentFunction;
                $currentFunction = '';
                $inFunction = false;
            }

            // Handle large functions
            if (strlen($currentFunction) > self::MAX_CHUNK_SIZE) {
                $chunks[] = $currentChunk.substr($currentFunction, 0, self::MAX_CHUNK_SIZE);
                $currentFunction = substr($currentFunction, self::MAX_CHUNK_SIZE - self::CHUNK_OVERLAP);
            }
        }

        // Add remaining content
        if (! empty($currentFunction)) {
            $chunks[] = $currentChunk.$currentFunction;
        }

        // If no chunks created, use text chunking
        if (empty($chunks)) {
            return $this->chunkTextFile($content, $filePath);
        }

        return $chunks;
    }

    /**
     * Chunk Python file.
     *
     * @return array<string>
     */
    private function chunkPythonFile(string $content, string $filePath): array
    {
        $chunks = [];
        $lines = explode("\n", $content);
        $currentChunk = "File: {$filePath}\n\n";
        $currentBlock = '';
        $indentLevel = 0;
        $inBlock = false;

        foreach ($lines as $line) {
            // Detect class or function definitions
            if (preg_match('/^(class|def|async def)\s+(\w+)/', $line, $matches)) {
                if ($inBlock && ! empty($currentBlock)) {
                    // Save previous block
                    $chunks[] = $currentChunk.$currentBlock;
                    $currentBlock = '';
                }
                $inBlock = true;
                $indentLevel = strlen($line) - strlen(ltrim($line));
            }

            if ($inBlock) {
                $currentBlock .= $line."\n";

                // Check if we're back to the original indent level
                $currentIndent = strlen($line) - strlen(ltrim($line));
                if (! empty(trim($line)) && $currentIndent <= $indentLevel && ! str_starts_with(trim($line), '#')) {
                    // End of block
                    $chunks[] = $currentChunk.$currentBlock;
                    $currentBlock = '';
                    $inBlock = false;
                }
            } else {
                $currentBlock .= $line."\n";
            }

            // Handle large blocks
            if (strlen($currentBlock) > self::MAX_CHUNK_SIZE) {
                $chunks[] = $currentChunk.substr($currentBlock, 0, self::MAX_CHUNK_SIZE);
                $currentBlock = substr($currentBlock, self::MAX_CHUNK_SIZE - self::CHUNK_OVERLAP);
            }
        }

        // Add remaining content
        if (! empty($currentBlock)) {
            $chunks[] = $currentChunk.$currentBlock;
        }

        // If no chunks created, use text chunking
        if (empty($chunks)) {
            return $this->chunkTextFile($content, $filePath);
        }

        return $chunks;
    }

    /**
     * Basic text-based chunking for other files.
     *
     * @return array<string>
     */
    private function chunkTextFile(string $content, string $filePath): array
    {
        $chunks = [];
        $lines = explode("\n", $content);
        $currentChunk = "File: {$filePath}\n\n";
        $chunkLines = [];
        $chunkSize = 0;

        foreach ($lines as $line) {
            $lineSize = strlen($line) + 1; // +1 for newline

            if ($chunkSize + $lineSize > self::MAX_CHUNK_SIZE && ! empty($chunkLines)) {
                // Save current chunk
                $chunks[] = $currentChunk.implode("\n", $chunkLines);

                // Start new chunk with overlap
                $overlapLines = array_slice($chunkLines, -5); // Last 5 lines
                $chunkLines = $overlapLines;
                $chunkSize = array_sum(array_map('strlen', $overlapLines)) + count($overlapLines);
            }

            $chunkLines[] = $line;
            $chunkSize += $lineSize;
        }

        // Add remaining lines
        if (! empty($chunkLines)) {
            $chunks[] = $currentChunk.implode("\n", $chunkLines);
        }

        return $chunks ?: [$currentChunk.$content];
    }

    /**
     * Split a large chunk into smaller pieces.
     *
     * @return array<string>
     */
    private function splitLargeChunk(string $chunk): array
    {
        $chunks = [];
        $lines = explode("\n", $chunk);
        $currentChunk = '';

        foreach ($lines as $line) {
            if (strlen($currentChunk.$line) > self::MAX_CHUNK_SIZE && ! empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = $line."\n";
            } else {
                $currentChunk .= $line."\n";
            }
        }

        if (! empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Find all indexable files in repository.
     *
     * @param  array<string>  $allowedPaths
     * @return array<string>
     */
    private function findIndexableFiles(string $repoPath, array $allowedPaths = []): array
    {
        $files = [];
        $extensions = array_keys(self::FILE_EXTENSIONS);

        // If no allowed paths, index everything
        if (empty($allowedPaths)) {
            $allowedPaths = ['*'];
        }

        foreach ($allowedPaths as $path) {
            $searchPath = $repoPath.'/'.$path;

            // Remove trailing wildcards for directory search
            $searchPath = rtrim($searchPath, '/*');

            if (is_dir($searchPath)) {
                // Search directory recursively
                foreach ($extensions as $ext) {
                    $found = glob($searchPath.'/**/*.'.$ext, GLOB_BRACE);
                    if ($found !== false) {
                        $files = array_merge($files, $found);
                    }
                }
            } elseif (is_file($searchPath)) {
                // Single file
                $files[] = $searchPath;
            } else {
                // Pattern matching
                $found = glob($searchPath);
                if ($found !== false) {
                    foreach ($found as $file) {
                        if (is_file($file)) {
                            $ext = pathinfo($file, PATHINFO_EXTENSION);
                            if (in_array($ext, $extensions)) {
                                $files[] = $file;
                            }
                        }
                    }
                }
            }
        }

        // Filter out common non-source directories
        $excludePatterns = [
            '/vendor/',
            '/node_modules/',
            '/.git/',
            '/dist/',
            '/build/',
            '/coverage/',
            '/.idea/',
            '/.vscode/',
        ];

        $files = array_filter($files, function ($file) use ($excludePatterns) {
            foreach ($excludePatterns as $pattern) {
                if (str_contains($file, $pattern)) {
                    return false;
                }
            }

            return true;
        });

        return array_unique($files);
    }

    /**
     * Store embedding in database.
     */
    private function storeEmbedding(
        int $projectId,
        string $filePath,
        string $content,
        VectorDto $vector,
        string $language,
        array $metadata,
    ): void {
        // Store in embeddings table
        DB::table('embeddings')->insert([
            'model_type' => 'App\\Models\\Project',
            'model_id' => $projectId,
            'metadata' => json_encode(array_merge($metadata, [
                'file_path' => $filePath,
                'language' => $language,
                'project_id' => $projectId,
                'indexed_at' => now()->toIso8601String(),
            ])),
            'embedding' => DB::raw("'[".implode(',', $vector->vector)."]'::vector"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Clear embeddings for a project.
     */
    public function clearProjectEmbeddings(int $projectId): int
    {
        return DB::table('embeddings')
            ->where('model_type', 'App\\Models\\Project')
            ->where('model_id', $projectId)
            ->delete();
    }
}

/**
 * PHP AST visitor for extracting code chunks.
 */
class PhpChunkVisitor extends \PhpParser\NodeVisitorAbstract
{
    private array $chunks = [];

    private string $content;

    private array $lines;

    public function __construct(string $content)
    {
        $this->content = $content;
        $this->lines = explode("\n", $content);
    }

    public function enterNode(\PhpParser\Node $node)
    {
        // Extract classes with their methods
        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            $startLine = $node->getStartLine() - 1;
            $endLine = $node->getEndLine() - 1;

            $classCode = $this->extractLines($startLine, $endLine);
            $this->chunks[] = "Class: {$node->name}\n\n".$classCode;
        }
        // Extract standalone functions
        elseif ($node instanceof \PhpParser\Node\Stmt\Function_) {
            $startLine = $node->getStartLine() - 1;
            $endLine = $node->getEndLine() - 1;

            $functionCode = $this->extractLines($startLine, $endLine);
            $this->chunks[] = "Function: {$node->name}\n\n".$functionCode;
        }
        // Extract interfaces
        elseif ($node instanceof \PhpParser\Node\Stmt\Interface_) {
            $startLine = $node->getStartLine() - 1;
            $endLine = $node->getEndLine() - 1;

            $interfaceCode = $this->extractLines($startLine, $endLine);
            $this->chunks[] = "Interface: {$node->name}\n\n".$interfaceCode;
        }
        // Extract traits
        elseif ($node instanceof \PhpParser\Node\Stmt\Trait_) {
            $startLine = $node->getStartLine() - 1;
            $endLine = $node->getEndLine() - 1;

            $traitCode = $this->extractLines($startLine, $endLine);
            $this->chunks[] = "Trait: {$node->name}\n\n".$traitCode;
        }

        return null;
    }

    private function extractLines(int $start, int $end): string
    {
        $extracted = array_slice($this->lines, $start, $end - $start + 1);

        return implode("\n", $extracted);
    }

    public function getChunks(): array
    {
        return $this->chunks;
    }
}
