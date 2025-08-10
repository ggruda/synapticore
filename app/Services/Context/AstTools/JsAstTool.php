<?php

declare(strict_types=1);

namespace App\Services\Context\AstTools;

use App\Services\WorkspaceRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * JavaScript/TypeScript AST manipulation tool.
 * Uses ts-morph via Node.js helper script.
 */
class JsAstTool
{
    private const HELPER_SCRIPT = 'ast-helper.js';

    public function __construct(
        private readonly WorkspaceRunner $runner,
    ) {}

    /**
     * Add a function to a JavaScript/TypeScript file.
     *
     * @throws \Exception
     */
    public function addFunction(
        string $filePath,
        string $functionName,
        string $functionCode,
        bool $isExported = true,
    ): string {
        $this->ensureHelperScript();

        $params = [
            'action' => 'addFunction',
            'filePath' => $filePath,
            'functionName' => $functionName,
            'functionCode' => $functionCode,
            'isExported' => $isExported,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to add function');
        }

        Log::info('Added function to JS/TS file', [
            'file' => $filePath,
            'function' => $functionName,
        ]);

        return $result['code'] ?? '';
    }

    /**
     * Modify an existing function.
     *
     * @throws \Exception
     */
    public function modifyFunction(
        string $filePath,
        string $functionName,
        string $newFunctionCode,
    ): string {
        $this->ensureHelperScript();

        $params = [
            'action' => 'modifyFunction',
            'filePath' => $filePath,
            'functionName' => $functionName,
            'newFunctionCode' => $newFunctionCode,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to modify function');
        }

        Log::info('Modified function in JS/TS file', [
            'file' => $filePath,
            'function' => $functionName,
        ]);

        return $result['code'] ?? '';
    }

    /**
     * Add a class to a TypeScript file.
     *
     * @throws \Exception
     */
    public function addClass(
        string $filePath,
        string $className,
        string $classCode,
        bool $isExported = true,
    ): string {
        $this->ensureHelperScript();

        $params = [
            'action' => 'addClass',
            'filePath' => $filePath,
            'className' => $className,
            'classCode' => $classCode,
            'isExported' => $isExported,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to add class');
        }

        Log::info('Added class to TS file', [
            'file' => $filePath,
            'class' => $className,
        ]);

        return $result['code'] ?? '';
    }

    /**
     * Add a method to a class.
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
        $this->ensureHelperScript();

        $params = [
            'action' => 'addMethod',
            'filePath' => $filePath,
            'className' => $className,
            'methodName' => $methodName,
            'methodCode' => $methodCode,
            'isPublic' => $isPublic,
            'isStatic' => $isStatic,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to add method');
        }

        Log::info('Added method to TS class', [
            'file' => $filePath,
            'class' => $className,
            'method' => $methodName,
        ]);

        return $result['code'] ?? '';
    }

    /**
     * Add an import to a file.
     *
     * @throws \Exception
     */
    public function addImport(
        string $filePath,
        string $moduleName,
        array $imports = [],
        bool $isDefault = false,
    ): string {
        $this->ensureHelperScript();

        $params = [
            'action' => 'addImport',
            'filePath' => $filePath,
            'moduleName' => $moduleName,
            'imports' => $imports,
            'isDefault' => $isDefault,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to add import');
        }

        Log::info('Added import to JS/TS file', [
            'file' => $filePath,
            'module' => $moduleName,
        ]);

        return $result['code'] ?? '';
    }

    /**
     * Find all functions in a file.
     *
     * @return array<string, array{isExported: bool, isAsync: bool, parameters: array}>
     *
     * @throws \Exception
     */
    public function findFunctions(string $filePath): array
    {
        $this->ensureHelperScript();

        $params = [
            'action' => 'findFunctions',
            'filePath' => $filePath,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to find functions');
        }

        return $result['functions'] ?? [];
    }

    /**
     * Find all classes in a TypeScript file.
     *
     * @return array<string, array{isExported: bool, methods: array, properties: array}>
     *
     * @throws \Exception
     */
    public function findClasses(string $filePath): array
    {
        $this->ensureHelperScript();

        $params = [
            'action' => 'findClasses',
            'filePath' => $filePath,
        ];

        $result = $this->runHelper($params);

        if (! $result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to find classes');
        }

        return $result['classes'] ?? [];
    }

    /**
     * Run the Node.js helper script.
     *
     * @return array{success: bool, code?: string, error?: string}
     */
    private function runHelper(array $params): array
    {
        $workspace = sys_get_temp_dir().'/ast-workspace';
        if (! is_dir($workspace)) {
            mkdir($workspace, 0755, true);
        }

        // Write parameters to temp file
        $paramsFile = $workspace.'/params.json';
        file_put_contents($paramsFile, json_encode($params));

        // Run the helper script
        $command = "node {$workspace}/".self::HELPER_SCRIPT." {$paramsFile}";

        $result = $this->runner->runDirect(
            workspacePath: $workspace,
            command: $command,
            timeout: 30,
        );

        if ($result->exitCode !== 0) {
            return [
                'success' => false,
                'error' => $result->stderr ?: 'Helper script failed',
            ];
        }

        // Parse the output
        $output = json_decode($result->stdout, true);
        if (! is_array($output)) {
            return [
                'success' => false,
                'error' => 'Invalid helper script output',
            ];
        }

        return $output;
    }

    /**
     * Ensure the helper script exists.
     */
    private function ensureHelperScript(): void
    {
        $workspace = sys_get_temp_dir().'/ast-workspace';
        if (! is_dir($workspace)) {
            mkdir($workspace, 0755, true);
        }

        $scriptPath = $workspace.'/'.self::HELPER_SCRIPT;
        if (! file_exists($scriptPath)) {
            $this->createHelperScript($scriptPath);
        }

        // Ensure package.json exists
        $packageJsonPath = $workspace.'/package.json';
        if (! file_exists($packageJsonPath)) {
            file_put_contents($packageJsonPath, json_encode([
                'name' => 'ast-helper',
                'version' => '1.0.0',
                'dependencies' => [
                    'ts-morph' => '^20.0.0',
                    'prettier' => '^3.0.0',
                ],
            ], JSON_PRETTY_PRINT));

            // Install dependencies
            $this->runner->runDirect(
                workspacePath: $workspace,
                command: 'npm install',
                timeout: 120,
            );
        }
    }

    /**
     * Create the Node.js helper script.
     */
    private function createHelperScript(string $path): void
    {
        $script = <<<'JS'
const { Project, VariableDeclarationKind } = require('ts-morph');
const fs = require('fs');
const prettier = require('prettier');

// Read parameters
const paramsFile = process.argv[2];
if (!paramsFile) {
    console.error('Usage: node ast-helper.js <params.json>');
    process.exit(1);
}

const params = JSON.parse(fs.readFileSync(paramsFile, 'utf8'));

// Create project
const project = new Project({
    compilerOptions: {
        target: 'ES2020',
        module: 'CommonJS',
        lib: ['ES2020'],
        jsx: 'react',
    },
});

async function formatCode(code, filePath) {
    try {
        const options = await prettier.resolveConfig(filePath) || {};
        return prettier.format(code, {
            ...options,
            filepath: filePath,
        });
    } catch (e) {
        return code; // Return unformatted if prettier fails
    }
}

async function handleAction(params) {
    const { action, filePath } = params;
    
    let sourceFile;
    if (fs.existsSync(filePath)) {
        sourceFile = project.addSourceFileAtPath(filePath);
    } else {
        sourceFile = project.createSourceFile(filePath, '');
    }

    switch (action) {
        case 'addFunction':
            return await addFunction(sourceFile, params);
        
        case 'modifyFunction':
            return await modifyFunction(sourceFile, params);
        
        case 'addClass':
            return await addClass(sourceFile, params);
        
        case 'addMethod':
            return await addMethod(sourceFile, params);
        
        case 'addImport':
            return await addImport(sourceFile, params);
        
        case 'findFunctions':
            return findFunctions(sourceFile);
        
        case 'findClasses':
            return findClasses(sourceFile);
        
        default:
            throw new Error(`Unknown action: ${action}`);
    }
}

async function addFunction(sourceFile, params) {
    const { functionName, functionCode, isExported } = params;
    
    // Check if function already exists
    const existingFunction = sourceFile.getFunction(functionName);
    if (existingFunction) {
        throw new Error(`Function ${functionName} already exists`);
    }
    
    // Add the function
    const exportKeyword = isExported ? 'export ' : '';
    sourceFile.addStatements(`${exportKeyword}${functionCode}`);
    
    // Format and save
    const code = await formatCode(sourceFile.getFullText(), sourceFile.getFilePath());
    await sourceFile.save();
    
    return { success: true, code };
}

async function modifyFunction(sourceFile, params) {
    const { functionName, newFunctionCode } = params;
    
    const func = sourceFile.getFunction(functionName);
    if (!func) {
        throw new Error(`Function ${functionName} not found`);
    }
    
    // Replace the function
    func.replaceWithText(newFunctionCode);
    
    // Format and save
    const code = await formatCode(sourceFile.getFullText(), sourceFile.getFilePath());
    await sourceFile.save();
    
    return { success: true, code };
}

async function addClass(sourceFile, params) {
    const { className, classCode, isExported } = params;
    
    // Check if class already exists
    const existingClass = sourceFile.getClass(className);
    if (existingClass) {
        throw new Error(`Class ${className} already exists`);
    }
    
    // Add the class
    const exportKeyword = isExported ? 'export ' : '';
    sourceFile.addStatements(`${exportKeyword}${classCode}`);
    
    // Format and save
    const code = await formatCode(sourceFile.getFullText(), sourceFile.getFilePath());
    await sourceFile.save();
    
    return { success: true, code };
}

async function addMethod(sourceFile, params) {
    const { className, methodName, methodCode, isPublic, isStatic } = params;
    
    const classDecl = sourceFile.getClass(className);
    if (!classDecl) {
        throw new Error(`Class ${className} not found`);
    }
    
    // Check if method already exists
    const existingMethod = classDecl.getMethod(methodName);
    if (existingMethod) {
        throw new Error(`Method ${methodName} already exists in class ${className}`);
    }
    
    // Add the method
    const scope = isPublic ? 'public' : 'private';
    const staticKeyword = isStatic ? 'static ' : '';
    classDecl.addMethod({
        name: methodName,
        scope,
        isStatic,
        statements: methodCode,
    });
    
    // Format and save
    const code = await formatCode(sourceFile.getFullText(), sourceFile.getFilePath());
    await sourceFile.save();
    
    return { success: true, code };
}

async function addImport(sourceFile, params) {
    const { moduleName, imports, isDefault } = params;
    
    if (isDefault && imports.length > 0) {
        sourceFile.addImportDeclaration({
            defaultImport: imports[0],
            moduleSpecifier: moduleName,
        });
    } else if (imports.length > 0) {
        sourceFile.addImportDeclaration({
            namedImports: imports,
            moduleSpecifier: moduleName,
        });
    } else {
        sourceFile.addImportDeclaration({
            moduleSpecifier: moduleName,
        });
    }
    
    // Format and save
    const code = await formatCode(sourceFile.getFullText(), sourceFile.getFilePath());
    await sourceFile.save();
    
    return { success: true, code };
}

function findFunctions(sourceFile) {
    const functions = {};
    
    sourceFile.getFunctions().forEach(func => {
        const name = func.getName();
        if (name) {
            functions[name] = {
                isExported: func.isExported(),
                isAsync: func.isAsync(),
                parameters: func.getParameters().map(p => ({
                    name: p.getName(),
                    type: p.getType().getText(),
                    hasDefault: p.hasInitializer(),
                })),
            };
        }
    });
    
    return { success: true, functions };
}

function findClasses(sourceFile) {
    const classes = {};
    
    sourceFile.getClasses().forEach(cls => {
        const name = cls.getName();
        if (name) {
            classes[name] = {
                isExported: cls.isExported(),
                methods: cls.getMethods().map(m => ({
                    name: m.getName(),
                    isStatic: m.isStatic(),
                    isAsync: m.isAsync(),
                    scope: m.getScope(),
                })),
                properties: cls.getProperties().map(p => ({
                    name: p.getName(),
                    type: p.getType().getText(),
                    isStatic: p.isStatic(),
                    scope: p.getScope(),
                })),
            };
        }
    });
    
    return { success: true, classes };
}

// Run the action
handleAction(params)
    .then(result => {
        console.log(JSON.stringify(result));
        process.exit(0);
    })
    .catch(error => {
        console.log(JSON.stringify({
            success: false,
            error: error.message,
        }));
        process.exit(1);
    });
JS;

        file_put_contents($path, $script);
    }
}
