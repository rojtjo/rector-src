<?php

declare(strict_types=1);

namespace Rector\Strict\Rector\Empty_;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Isset_;
use PHPStan\Analyser\Scope;
use Rector\Strict\NodeFactory\ExactCompareFactory;
use Rector\Strict\Rector\AbstractFalsyScalarRuleFixerRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector\DisallowedEmptyRuleFixerRectorTest
 */
final class DisallowedEmptyRuleFixerRector extends AbstractFalsyScalarRuleFixerRector
{
    public function __construct(
        private readonly ExactCompareFactory $exactCompareFactory,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        $errorMessage = \sprintf(
            'Fixer for PHPStan reports by strict type rule - "%s"',
            'PHPStan\Rules\DisallowedConstructs\DisallowedEmptyRule'
        );
        return new RuleDefinition($errorMessage, [
            new ConfiguredCodeSample(
                <<<'CODE_SAMPLE'
final class SomeEmptyArray
{
    public function run(array $items)
    {
        return empty($items);
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
final class SomeEmptyArray
{
    public function run(array $items)
    {
        return $items === [];
    }
}
CODE_SAMPLE
                ,
                [
                    self::TREAT_AS_NON_EMPTY => false,
                ]
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Empty_::class, BooleanNot::class];
    }

    /**
     * @param Empty_|BooleanNot $node
     */
    public function refactorWithScope(Node $node, Scope $scope): Expr|null
    {
        if ($node instanceof BooleanNot) {
            return $this->refactorBooleanNot($node, $scope);
        }

        if ($node->expr instanceof ArrayDimFetch) {
            return null;
        }

        return $this->refactorEmpty($node, $scope, $this->treatAsNonEmpty);
    }

    private function refactorBooleanNot(BooleanNot $booleanNot, Scope $scope): Expr|null
    {
        if (! $booleanNot->expr instanceof Empty_) {
            return null;
        }

        $empty = $booleanNot->expr;
        if ($empty->expr instanceof ArrayDimFetch) {
            return $this->createDimFetchBooleanAnd($empty);
        }

        $emptyExprType = $scope->getType($empty->expr);

        return $this->exactCompareFactory->createNotIdenticalFalsyCompare(
            $emptyExprType,
            $empty->expr,
            $this->treatAsNonEmpty
        );
    }

    private function refactorEmpty(Empty_ $empty, Scope $scope, bool $treatAsNonEmpty): Expr|null
    {
        $exprType = $scope->getType($empty->expr);
        return $this->exactCompareFactory->createIdenticalFalsyCompare($exprType, $empty->expr, $treatAsNonEmpty);
    }

    private function createDimFetchBooleanAnd(Empty_ $empty): ?BooleanAnd
    {
        $exprType = $this->getType($empty->expr);

        $isset = new Isset_([$empty->expr]);
        $compareExpr = $this->exactCompareFactory->createNotIdenticalFalsyCompare($exprType, $empty->expr, false);

        if (! $compareExpr instanceof Expr) {
            return null;
        }

        return new BooleanAnd($isset, $compareExpr);
    }
}
