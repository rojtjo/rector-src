<?php

declare(strict_types=1);

namespace Rector\CodeQuality\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use Rector\CodingStyle\Reflection\VendorLocationDetector;
use Rector\Core\Rector\AbstractScopeAwareRector;
use Rector\Core\Reflection\ReflectionResolver;
use Rector\NodeTypeResolver\PHPStan\ParametersAcceptorSelectorVariantsWrapper;
use Rector\Php80\NodeResolver\ArgumentSorter;
use Rector\Php80\NodeResolver\RequireOptionalParamResolver;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog https://php.watch/versions/8.0#deprecate-required-param-after-optional
 *
 * @see \Rector\Tests\CodeQuality\Rector\ClassMethod\OptionalParametersAfterRequiredRector\OptionalParametersAfterRequiredRectorTest
 */
final class OptionalParametersAfterRequiredRector extends AbstractScopeAwareRector
{
    /**
     * @var string
     */
    private const HAS_SWAPPED_PARAMS = 'has_swapped_params';

    public function __construct(
        private readonly RequireOptionalParamResolver $requireOptionalParamResolver,
        private readonly ArgumentSorter $argumentSorter,
        private readonly ReflectionResolver $reflectionResolver,
        private readonly VendorLocationDetector $vendorLocationDetector
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Move required parameters after optional ones', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeObject
{
    public function run($optional = 1, $required)
    {
    }
}
CODE_SAMPLE

                ,
                <<<'CODE_SAMPLE'
class SomeObject
{
    public function run($required, $optional = 1)
    {
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
        return [ClassMethod::class, New_::class, MethodCall::class, StaticCall::class];
    }

    /**
     * @param ClassMethod|New_|MethodCall|StaticCall $node
     */
    public function refactorWithScope(Node $node, Scope $scope): ClassMethod|null|New_|MethodCall|StaticCall
    {
        if ($node instanceof ClassMethod) {
            return $this->refactorClassMethod($node, $scope);
        }

        if ($node instanceof New_) {
            return $this->refactorNew($node, $scope);
        }

        return $this->refactorMethodCall($node, $scope);
    }

    private function refactorClassMethod(ClassMethod $classMethod, Scope $scope): ?ClassMethod
    {
        if ($classMethod->params === []) {
            return null;
        }

        if ($classMethod->getAttribute(self::HAS_SWAPPED_PARAMS, false) === true) {
            return null;
        }

        $classMethodReflection = $this->reflectionResolver->resolveMethodReflectionFromClassMethod($classMethod);
        if (! $classMethodReflection instanceof MethodReflection) {
            return null;
        }

        $expectedArgOrParamOrder = $this->resolveExpectedArgParamOrderIfDifferent(
            $classMethodReflection,
            $classMethod,
            $scope
        );
        if ($expectedArgOrParamOrder === null) {
            return null;
        }

        $classMethod->params = $this->argumentSorter->sortArgsByExpectedParamOrder(
            $classMethod->params,
            $expectedArgOrParamOrder
        );

        $classMethod->setAttribute(self::HAS_SWAPPED_PARAMS, true);
        return $classMethod;
    }

    private function refactorNew(New_ $new, Scope $scope): ?New_
    {
        if ($new->args === []) {
            return null;
        }

        if ($new->isFirstClassCallable()) {
            return null;
        }

        $methodReflection = $this->reflectionResolver->resolveMethodReflectionFromNew($new);
        if (! $methodReflection instanceof MethodReflection) {
            return null;
        }

        $expectedArgOrParamOrder = $this->resolveExpectedArgParamOrderIfDifferent($methodReflection, $new, $scope);
        if ($expectedArgOrParamOrder === null) {
            return null;
        }

        $new->args = $this->argumentSorter->sortArgsByExpectedParamOrder($new->getArgs(), $expectedArgOrParamOrder);

        return $new;
    }

    private function refactorMethodCall(MethodCall|StaticCall $methodCall, Scope $scope): MethodCall|StaticCall|null
    {
        if ($methodCall->isFirstClassCallable()) {
            return null;
        }

        $methodReflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($methodCall);
        if (! $methodReflection instanceof MethodReflection) {
            return null;
        }

        $expectedArgOrParamOrder = $this->resolveExpectedArgParamOrderIfDifferent(
            $methodReflection,
            $methodCall,
            $scope
        );
        if ($expectedArgOrParamOrder === null) {
            return null;
        }

        $newArgs = $this->argumentSorter->sortArgsByExpectedParamOrder(
            $methodCall->getArgs(),
            $expectedArgOrParamOrder
        );

        if ($methodCall->args === $newArgs) {
            return null;
        }

        $methodCall->args = $newArgs;
        return $methodCall;
    }

    /**
     * @return int[]|null
     */
    private function resolveExpectedArgParamOrderIfDifferent(
        MethodReflection $methodReflection,
        New_|MethodCall|ClassMethod|StaticCall $node,
        Scope $scope
    ): ?array {
        if ($this->vendorLocationDetector->detectMethodReflection($methodReflection)) {
            return null;
        }

        $parametersAcceptor = ParametersAcceptorSelectorVariantsWrapper::select($methodReflection, $node, $scope);
        $expectedParameterReflections = $this->requireOptionalParamResolver->resolveFromParametersAcceptor(
            $parametersAcceptor
        );

        if ($expectedParameterReflections === $parametersAcceptor->getParameters()) {
            return null;
        }

        return array_keys($expectedParameterReflections);
    }
}
