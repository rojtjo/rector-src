<?php

declare(strict_types=1);

namespace Rector\PHPStanStaticTypeMapper\TypeMapper;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\Php\PhpVersionProvider;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\PHPStanStaticTypeMapper\Contract\TypeMapperInterface;

/**
 * @implements TypeMapperInterface<TypeWithClassName>
 */
final class TypeWithClassNameTypeMapper implements TypeMapperInterface
{
    public function __construct(
        private readonly PhpVersionProvider $phpVersionProvider
    ) {
    }

    /**
     * @return class-string<Type>
     */
    public function getNodeClass(): string
    {
        return TypeWithClassName::class;
    }

    /**
     * @param TypeWithClassName $type
     */
    public function mapToPHPStanPhpDocTypeNode(Type $type): TypeNode
    {
        return new IdentifierTypeNode('string-class');
    }

    /**
     * @param TypeWithClassName $type
     */
    public function mapToPhpParserNode(Type $type, string $typeKind): ?Node
    {
        if (! $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::SCALAR_TYPES)) {
            return null;
        }

        return new Identifier('string');
    }
}
