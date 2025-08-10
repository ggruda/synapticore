<?php

declare(strict_types=1);

namespace App\Services\Context\AstTools;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PhpParser\BuilderFactory;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * PHP AST manipulation tool using nikic/php-parser.
 * Provides safe methods to locate and modify PHP code.
 */
class PhpAstTool
{
    private \PhpParser\Parser $parser;

    private PrettyPrinter\Standard $printer;

    private BuilderFactory $factory;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->printer = new PrettyPrinter\Standard;
        $this->factory = new BuilderFactory;
        $this->finder = new NodeFinder;
    }

    /**
     * Add a new method to a class.
     *
     * @throws \Exception
     */
    public function addMethod(
        string $filePath,
        string $className,
        string $methodName,
        string $methodCode,
        bool $isPublic = true,
        bool $isStatic = false,
    ): string {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("Cannot read file: {$filePath}");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                throw new \Exception('Failed to parse PHP file');
            }

            // Find the class
            $class = $this->finder->findFirst($ast, function (Node $node) use ($className) {
                return $node instanceof Node\Stmt\Class_
                    && $node->name->toString() === $className;
            });

            if (! $class instanceof Node\Stmt\Class_) {
                throw new \Exception("Class {$className} not found");
            }

            // Check if method already exists
            $existingMethod = $this->finder->findFirst($class->stmts, function (Node $node) use ($methodName) {
                return $node instanceof Node\Stmt\ClassMethod
                    && $node->name->toString() === $methodName;
            });

            if ($existingMethod !== null) {
                throw new \Exception("Method {$methodName} already exists in class {$className}");
            }

            // Parse the method code
            $methodAst = $this->parser->parse("<?php class Dummy { {$methodCode} }");
            if (! $methodAst || ! isset($methodAst[0])) {
                throw new \Exception('Failed to parse method code');
            }

            $dummyClass = $methodAst[0];
            if (! $dummyClass instanceof Node\Stmt\Class_ || empty($dummyClass->stmts)) {
                throw new \Exception('Invalid method code');
            }

            $newMethod = $dummyClass->stmts[0];
            if (! $newMethod instanceof Node\Stmt\ClassMethod) {
                throw new \Exception('Parsed code is not a method');
            }

            // Set visibility
            if ($isPublic) {
                $newMethod->flags = Node\Stmt\Class_::MODIFIER_PUBLIC;
            } else {
                $newMethod->flags = Node\Stmt\Class_::MODIFIER_PRIVATE;
            }

            if ($isStatic) {
                $newMethod->flags |= Node\Stmt\Class_::MODIFIER_STATIC;
            }

            // Add method to class
            $class->stmts[] = $newMethod;

            // Generate new code
            $newCode = $this->printer->prettyPrintFile($ast);

            // Write back to file
            file_put_contents($filePath, $newCode);

            // Format the file
            $this->formatPhpFile($filePath);

            Log::info('Added method to PHP class', [
                'file' => $filePath,
                'class' => $className,
                'method' => $methodName,
            ]);

            return $newCode;
        } catch (Error $e) {
            throw new \Exception("PHP parsing error: {$e->getMessage()}");
        }
    }

    /**
     * Modify an existing method in a class.
     *
     * @throws \Exception
     */
    public function modifyMethod(
        string $filePath,
        string $className,
        string $methodName,
        string $newMethodCode,
    ): string {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("Cannot read file: {$filePath}");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                throw new \Exception('Failed to parse PHP file');
            }

            // Find the class
            $class = $this->finder->findFirst($ast, function (Node $node) use ($className) {
                return $node instanceof Node\Stmt\Class_
                    && $node->name->toString() === $className;
            });

            if (! $class instanceof Node\Stmt\Class_) {
                throw new \Exception("Class {$className} not found");
            }

            // Find and replace the method
            $methodFound = false;
            foreach ($class->stmts as $key => $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                    // Parse the new method code
                    $methodAst = $this->parser->parse("<?php class Dummy { {$newMethodCode} }");
                    if (! $methodAst || ! isset($methodAst[0])) {
                        throw new \Exception('Failed to parse new method code');
                    }

                    $dummyClass = $methodAst[0];
                    if (! $dummyClass instanceof Node\Stmt\Class_ || empty($dummyClass->stmts)) {
                        throw new \Exception('Invalid method code');
                    }

                    $newMethod = $dummyClass->stmts[0];
                    if (! $newMethod instanceof Node\Stmt\ClassMethod) {
                        throw new \Exception('Parsed code is not a method');
                    }

                    // Preserve original visibility
                    $newMethod->flags = $stmt->flags;

                    // Replace the method
                    $class->stmts[$key] = $newMethod;
                    $methodFound = true;
                    break;
                }
            }

            if (! $methodFound) {
                throw new \Exception("Method {$methodName} not found in class {$className}");
            }

            // Generate new code
            $newCode = $this->printer->prettyPrintFile($ast);

            // Write back to file
            file_put_contents($filePath, $newCode);

            // Format the file
            $this->formatPhpFile($filePath);

            Log::info('Modified method in PHP class', [
                'file' => $filePath,
                'class' => $className,
                'method' => $methodName,
            ]);

            return $newCode;
        } catch (Error $e) {
            throw new \Exception("PHP parsing error: {$e->getMessage()}");
        }
    }

    /**
     * Add a property to a class.
     *
     * @throws \Exception
     */
    public function addProperty(
        string $filePath,
        string $className,
        string $propertyName,
        ?string $type = null,
        bool $isPublic = false,
        bool $isStatic = false,
        mixed $defaultValue = null,
    ): string {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("Cannot read file: {$filePath}");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                throw new \Exception('Failed to parse PHP file');
            }

            // Find the class
            $class = $this->finder->findFirst($ast, function (Node $node) use ($className) {
                return $node instanceof Node\Stmt\Class_
                    && $node->name->toString() === $className;
            });

            if (! $class instanceof Node\Stmt\Class_) {
                throw new \Exception("Class {$className} not found");
            }

            // Check if property already exists
            $existingProperty = $this->finder->findFirst($class->stmts, function (Node $node) use ($propertyName) {
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() === $propertyName) {
                            return true;
                        }
                    }
                }

                return false;
            });

            if ($existingProperty !== null) {
                throw new \Exception("Property {$propertyName} already exists in class {$className}");
            }

            // Build the property
            $property = $this->factory->property($propertyName);

            if ($isPublic) {
                $property->makePublic();
            } else {
                $property->makePrivate();
            }

            if ($isStatic) {
                $property->makeStatic();
            }

            if ($type !== null) {
                $property->setType($type);
            }

            if ($defaultValue !== null) {
                $property->setDefault($defaultValue);
            }

            // Add property to class (insert at the beginning)
            array_unshift($class->stmts, $property->getNode());

            // Generate new code
            $newCode = $this->printer->prettyPrintFile($ast);

            // Write back to file
            file_put_contents($filePath, $newCode);

            // Format the file
            $this->formatPhpFile($filePath);

            Log::info('Added property to PHP class', [
                'file' => $filePath,
                'class' => $className,
                'property' => $propertyName,
            ]);

            return $newCode;
        } catch (Error $e) {
            throw new \Exception("PHP parsing error: {$e->getMessage()}");
        }
    }

    /**
     * Find all methods in a class.
     *
     * @return array<string, array{visibility: string, isStatic: bool, parameters: array}>
     *
     * @throws \Exception
     */
    public function findClassMethods(string $filePath, string $className): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("Cannot read file: {$filePath}");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                throw new \Exception('Failed to parse PHP file');
            }

            // Find the class
            $class = $this->finder->findFirst($ast, function (Node $node) use ($className) {
                return $node instanceof Node\Stmt\Class_
                    && $node->name->toString() === $className;
            });

            if (! $class instanceof Node\Stmt\Class_) {
                throw new \Exception("Class {$className} not found");
            }

            $methods = [];
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod) {
                    $visibility = 'public';
                    if ($stmt->isPrivate()) {
                        $visibility = 'private';
                    } elseif ($stmt->isProtected()) {
                        $visibility = 'protected';
                    }

                    $parameters = [];
                    foreach ($stmt->params as $param) {
                        $paramInfo = [
                            'name' => $param->var instanceof Node\Expr\Variable ? $param->var->name : 'unknown',
                            'type' => $param->type ? $this->getTypeName($param->type) : null,
                            'default' => $param->default !== null,
                        ];
                        $parameters[] = $paramInfo;
                    }

                    $methods[$stmt->name->toString()] = [
                        'visibility' => $visibility,
                        'isStatic' => $stmt->isStatic(),
                        'parameters' => $parameters,
                    ];
                }
            }

            return $methods;
        } catch (Error $e) {
            throw new \Exception("PHP parsing error: {$e->getMessage()}");
        }
    }

    /**
     * Add an import (use statement) to a file.
     *
     * @throws \Exception
     */
    public function addImport(string $filePath, string $className, ?string $alias = null): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("Cannot read file: {$filePath}");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                throw new \Exception('Failed to parse PHP file');
            }

            // Check if import already exists
            $existingImport = $this->finder->findFirst($ast, function (Node $node) use ($className) {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        if ($use->name->toString() === $className) {
                            return true;
                        }
                    }
                }

                return false;
            });

            if ($existingImport !== null) {
                Log::info('Import already exists', ['class' => $className]);

                return $content;
            }

            // Build the use statement
            $use = $this->factory->use($className);
            if ($alias !== null) {
                $use->as($alias);
            }

            // Find the position to insert (after namespace, before class)
            $insertIndex = 0;
            foreach ($ast as $index => $node) {
                if ($node instanceof Node\Stmt\Namespace_) {
                    // Insert into namespace statements
                    $namespace = $node;
                    $namespaceInsertIndex = 0;

                    foreach ($namespace->stmts as $nsIndex => $nsNode) {
                        if ($nsNode instanceof Node\Stmt\Use_) {
                            $namespaceInsertIndex = $nsIndex + 1;
                        } elseif ($nsNode instanceof Node\Stmt\Class_ || $nsNode instanceof Node\Stmt\Interface_) {
                            break;
                        }
                    }

                    array_splice($namespace->stmts, $namespaceInsertIndex, 0, [$use->getNode()]);

                    break;
                } elseif ($node instanceof Node\Stmt\Use_) {
                    $insertIndex = $index + 1;
                } elseif ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_) {
                    // Insert before class/interface
                    array_splice($ast, $insertIndex, 0, [$use->getNode()]);
                    break;
                }
            }

            // Generate new code
            $newCode = $this->printer->prettyPrintFile($ast);

            // Write back to file
            file_put_contents($filePath, $newCode);

            // Format the file
            $this->formatPhpFile($filePath);

            Log::info('Added import to PHP file', [
                'file' => $filePath,
                'import' => $className,
                'alias' => $alias,
            ]);

            return $newCode;
        } catch (Error $e) {
            throw new \Exception("PHP parsing error: {$e->getMessage()}");
        }
    }

    /**
     * Format PHP file using available formatter.
     */
    private function formatPhpFile(string $filePath): void
    {
        // Try Laravel Pint first
        $pintPath = base_path('vendor/bin/pint');
        if (file_exists($pintPath)) {
            $result = Process::run("{$pintPath} {$filePath}");
            if ($result->successful()) {
                Log::debug('Formatted PHP file with Pint', ['file' => $filePath]);

                return;
            }
        }

        // Try PHP-CS-Fixer
        $fixerPath = base_path('vendor/bin/php-cs-fixer');
        if (file_exists($fixerPath)) {
            $result = Process::run("{$fixerPath} fix {$filePath}");
            if ($result->successful()) {
                Log::debug('Formatted PHP file with PHP-CS-Fixer', ['file' => $filePath]);

                return;
            }
        }

        Log::warning('No PHP formatter available', ['file' => $filePath]);
    }

    /**
     * Get type name from type node.
     */
    private function getTypeName(Node $type): string
    {
        if ($type instanceof Node\Name) {
            return $type->toString();
        } elseif ($type instanceof Node\Identifier) {
            return $type->toString();
        } elseif ($type instanceof Node\UnionType) {
            $types = [];
            foreach ($type->types as $t) {
                $types[] = $this->getTypeName($t);
            }

            return implode('|', $types);
        } elseif ($type instanceof Node\NullableType) {
            return '?'.$this->getTypeName($type->type);
        }

        return 'mixed';
    }
}
