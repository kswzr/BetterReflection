<?php

declare(strict_types=1);

namespace Roave\BetterReflection\SourceLocator\SourceStubber;

use JetBrains\PHPStormStub\PhpStormStubsMap;
use PhpParser\BuilderHelpers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use Roave\BetterReflection\Reflection\Exception\InvalidConstantNode;
use Roave\BetterReflection\SourceLocator\FileChecker;
use Roave\BetterReflection\SourceLocator\SourceStubber\Exception\CouldNotFindPhpStormStubs;
use Roave\BetterReflection\Util\ConstantNodeChecker;
use Traversable;
use function array_key_exists;
use function assert;
use function constant;
use function count;
use function defined;
use function explode;
use function file_get_contents;
use function in_array;
use function is_dir;
use function is_string;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * @internal
 */
final class PhpStormStubsSourceStubber implements SourceStubber
{
    private const BUILDER_OPTIONS    = ['shortArraySyntax' => true];
    private const SEARCH_DIRECTORIES = [
        __DIR__ . '/../../../../../jetbrains/phpstorm-stubs',
        __DIR__ . '/../../../vendor/jetbrains/phpstorm-stubs',
    ];

    /** @var Parser */
    private $phpParser;

    /** @var Standard */
    private $prettyPrinter;

    /** @var NodeTraverser */
    private $nodeTraverser;

    /** @var string|null */
    private $stubsDirectory;

    /** @var NodeVisitorAbstract */
    private $cachingVisitor;

    /** @var array<string, Node\Stmt\ClassLike> */
    private $classNodes = [];

    /** @var array<string, Node\Stmt\Function_> */
    private $functionNodes = [];

    /** @var array<string, Node\Stmt\Const_|Node\Expr\FuncCall> */
    private $constantNodes = [];

    public function __construct(Parser $phpParser)
    {
        $this->phpParser     = $phpParser;
        $this->prettyPrinter = new Standard(self::BUILDER_OPTIONS);

        $this->cachingVisitor = $this->createCachingVisitor();

        $this->nodeTraverser = new NodeTraverser();
        $this->nodeTraverser->addVisitor(new NameResolver());
        $this->nodeTraverser->addVisitor($this->cachingVisitor);
    }

    public function generateClassStub(string $className) : ?StubData
    {
        if (! array_key_exists($className, PhpStormStubsMap::CLASSES)) {
            return null;
        }

        $filePath = PhpStormStubsMap::CLASSES[$className];

        if (! array_key_exists($className, $this->classNodes)) {
            $this->parseFile($filePath);
        }

        $stub = $this->createStub($this->classNodes[$className]);

        if ($className === Traversable::class) {
            // See https://github.com/JetBrains/phpstorm-stubs/commit/0778a26992c47d7dbee4d0b0bfb7fad4344371b1#diff-575bacb45377d474336c71cbf53c1729
            $stub = str_replace(' extends \iterable', '', $stub);
        }

        return new StubData($stub, $this->getExtensionFromFilePath($filePath));
    }

    public function generateFunctionStub(string $functionName) : ?StubData
    {
        if (! array_key_exists($functionName, PhpStormStubsMap::FUNCTIONS)) {
            return null;
        }

        $filePath = PhpStormStubsMap::FUNCTIONS[$functionName];

        if (! array_key_exists($functionName, $this->functionNodes)) {
            $this->parseFile($filePath);
        }

        return new StubData($this->createStub($this->functionNodes[$functionName]), $this->getExtensionFromFilePath($filePath));
    }

    public function generateConstantStub(string $constantName) : ?StubData
    {
        // https://github.com/JetBrains/phpstorm-stubs/pull/591
        if (in_array($constantName, ['TRUE', 'FALSE', 'NULL'], true)) {
            $constantName = strtolower($constantName);
        }

        if (! array_key_exists($constantName, PhpStormStubsMap::CONSTANTS)) {
            return null;
        }

        $filePath = PhpStormStubsMap::CONSTANTS[$constantName];

        if (! array_key_exists($constantName, $this->constantNodes)) {
            $this->parseFile($filePath);
        }

        return new StubData($this->createStub($this->constantNodes[$constantName]), $this->getExtensionFromFilePath($filePath));
    }

    private function parseFile(string $filePath) : void
    {
        $absoluteFilePath = $this->getAbsoluteFilePath($filePath);
        FileChecker::assertReadableFile($absoluteFilePath);

        $ast = $this->phpParser->parse(file_get_contents($absoluteFilePath));

        /** @psalm-suppress UndefinedMethod */
        $this->cachingVisitor->clearNodes();

        $this->nodeTraverser->traverse($ast);

        /**
         * @psalm-suppress UndefinedMethod
         */
        foreach ($this->cachingVisitor->getClassNodes() as $className => $classNode) {
            assert(is_string($className));
            assert($classNode instanceof Node\Stmt\ClassLike);
            $this->classNodes[$className] = $classNode;
        }

        /**
         * @psalm-suppress UndefinedMethod
         */
        foreach ($this->cachingVisitor->getFunctionNodes() as $functionName => $functionNode) {
            assert(is_string($functionName));
            assert($functionNode instanceof Node\Stmt\Function_);
            $this->functionNodes[$functionName] = $functionNode;
        }

        /**
         * @psalm-suppress UndefinedMethod
         */
        foreach ($this->cachingVisitor->getConstantNodes() as $constantName => $constantNode) {
            assert(is_string($constantName));
            assert($constantNode instanceof Node\Stmt\Const_ || $constantNode instanceof Node\Expr\FuncCall);
            $this->constantNodes[$constantName] = $constantNode;
        }
    }

    private function createStub(Node $node) : string
    {
        return "<?php\n\n" . $this->prettyPrinter->prettyPrint([$node]) . ($node instanceof Node\Expr\FuncCall ? ';' : '') . "\n";
    }

    private function createCachingVisitor() : NodeVisitorAbstract
    {
        return new class() extends NodeVisitorAbstract
        {
            /** @var array<string, Node\Stmt\ClassLike> */
            private $classNodes = [];

            /** @var array<string, Node\Stmt\Function_> */
            private $functionNodes = [];

            /** @var array<string, Node\Stmt\Const_|Node\Expr\FuncCall> */
            private $constantNodes = [];

            public function enterNode(Node $node) : ?int
            {
                if ($node instanceof Node\Stmt\ClassLike) {
                    $nodeName                    = (string) $node->namespacedName->toString();
                    $this->classNodes[$nodeName] = $node;

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Stmt\Function_) {
                    /** @psalm-suppress UndefinedPropertyFetch */
                    $nodeName                       = (string) $node->namespacedName->toString();
                    $this->functionNodes[$nodeName] = $node;

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Stmt\Const_) {
                    foreach ($node->consts as $constNode) {
                        /** @psalm-suppress UndefinedPropertyFetch */
                        $constNodeName                       = (string) $constNode->namespacedName->toString();
                        $this->constantNodes[$constNodeName] = $node;
                    }

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Expr\FuncCall) {
                    try {
                        ConstantNodeChecker::assertValidDefineFunctionCall($node);
                    } catch (InvalidConstantNode $e) {
                        return null;
                    }

                    $nameNode = $node->args[0]->value;
                    assert($nameNode instanceof Node\Scalar\String_);
                    $constantName = $nameNode->value;

                    // Some constants has different values on different systems, some are not actual in stubs
                    if (defined($constantName)) {
                        /** @psalm-var scalar|scalar[]|null $constantValue */
                        $constantValue        = constant($constantName);
                        $node->args[1]->value = BuilderHelpers::normalizeValue($constantValue);
                    }

                    $this->constantNodes[$constantName] = $node;

                    if (count($node->args) === 3
                        && $node->args[2]->value instanceof Node\Expr\ConstFetch
                        && $node->args[2]->value->name->toLowerString() === 'true'
                    ) {
                        $this->constantNodes[strtolower($constantName)] = $node;
                    }

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }

            /**
             * @return array<string, Node\Stmt\ClassLike>
             */
            public function getClassNodes() : array
            {
                return $this->classNodes;
            }

            /**
             * @return array<string, Node\Stmt\Function_>
             */
            public function getFunctionNodes() : array
            {
                return $this->functionNodes;
            }

            /**
             * @return array<string, Node\Stmt\Const_|Node\Expr\FuncCall>
             */
            public function getConstantNodes() : array
            {
                return $this->constantNodes;
            }

            public function clearNodes() : void
            {
                $this->classNodes    = [];
                $this->functionNodes = [];
                $this->constantNodes = [];
            }
        };
    }

    private function getExtensionFromFilePath(string $filePath) : string
    {
        return explode('/', $filePath)[0];
    }

    private function getAbsoluteFilePath(string $filePath) : string
    {
        return sprintf('%s/%s', $this->getStubsDirectory(), $filePath);
    }

    private function getStubsDirectory() : string
    {
        if ($this->stubsDirectory !== null) {
            return $this->stubsDirectory;
        }

        foreach (self::SEARCH_DIRECTORIES as $directory) {
            if (is_dir($directory)) {
                return $this->stubsDirectory = $directory;
            }
        }

        throw CouldNotFindPhpStormStubs::create();
    }
}
