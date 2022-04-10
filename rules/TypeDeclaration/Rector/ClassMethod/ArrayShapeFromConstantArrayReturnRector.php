<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTypeChanger;
use Rector\BetterPhpDocParser\ValueObject\Type\SpacingAwareArrayTypeNode;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\Util\StringUtils;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PHPStanStaticTypeMapper\Enum\TypeKind;
use Symplify\Astral\TypeAnalyzer\ClassMethodReturnTypeResolver;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\TypeDeclaration\Rector\ClassMethod\ArrayShapeFromConstantArrayReturnRector\ArrayShapeFromConstantArrayReturnRectorTest
 */
final class ArrayShapeFromConstantArrayReturnRector extends AbstractRector
{
    /**
     * @see https://regex101.com/r/WvUD0m/2
     * @var string
     */
    private const SKIPPED_CHAR_REGEX = '#\W#u';

    public function __construct(
        private readonly ClassMethodReturnTypeResolver $classMethodReturnTypeResolver,
        private readonly PhpDocTypeChanger $phpDocTypeChanger
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Add array shape exact types based on constant keys of array', [
            new CodeSample(
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function run(string $name)
    {
        return ['name' => $name];
    }
}
CODE_SAMPLE
,
                <<<'CODE_SAMPLE'
final class SomeClass
{
    /**
     * @return array{name: string}
     */
    public function run(string $name)
    {
        return ['name' => $name];
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        /** @var Return_[] $returns */
        $returns = $this->betterNodeFinder->findInstancesOfInFunctionLikeScoped($node, Return_::class);
        // exact one shape only
        if (count($returns) !== 1) {
            return null;
        }

        $return = $returns[0];
        if (! $return->expr instanceof Expr) {
            return null;
        }

        $returnExprType = $this->getType($return->expr);
        if (! $returnExprType instanceof ConstantArrayType) {
            return null;
        }

        $keyType = $returnExprType->getKeyType();
        if ($this->shouldSkipKeyType($keyType)) {
            return null;
        }

        $returnType = $this->classMethodReturnTypeResolver->resolve($node, $node->getAttribute(AttributeKey::SCOPE));
        if ($returnType instanceof ConstantArrayType) {
            return null;
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);

        $returnExprTypeNode = $this->staticTypeMapper->mapPHPStanTypeToPHPStanPhpDocTypeNode(
            $returnExprType,
            TypeKind::RETURN()
        );

        if ($returnExprTypeNode instanceof GenericTypeNode) {
            return null;
        }

        if ($returnExprTypeNode instanceof SpacingAwareArrayTypeNode) {
            return null;
        }

        $hasChanged = $this->phpDocTypeChanger->changeReturnType($phpDocInfo, $returnExprType);
        if (! $hasChanged) {
            return null;
        }

        return $node;
    }

    private function shouldSkipKeyType(Type $keyType): bool
    {
        // empty array
        if ($keyType instanceof NeverType) {
            return true;
        }

        $types = $keyType instanceof UnionType
            ? $keyType->getTypes()
            : [$keyType];

        foreach ($types as $type) {
            if (! $type instanceof ConstantStringType) {
                continue;
            }

            $value = $type->getValue();

            if (trim($value) === '') {
                return true;
            }

            if (StringUtils::isMatch($value, self::SKIPPED_CHAR_REGEX)) {
                return true;
            }
        }

        return false;
    }
}