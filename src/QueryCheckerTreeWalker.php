<?php declare(strict_types = 1);

namespace ShipMonk\DoctrineQueryChecker;

use BackedEnum;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\ArithmeticExpression;
use Doctrine\ORM\Query\AST\ComparisonExpression;
use Doctrine\ORM\Query\AST\ConditionalExpression;
use Doctrine\ORM\Query\AST\ConditionalFactor;
use Doctrine\ORM\Query\AST\ConditionalPrimary;
use Doctrine\ORM\Query\AST\ConditionalTerm;
use Doctrine\ORM\Query\AST\HavingClause;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\Phase2OptimizableConditional;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\WhereClause;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use Psr\Log\LoggerInterface;
use ShipMonk\DoctrineQueryChecker\Exception\LogicException;
use Throwable;
use WeakReference;
use function array_map;
use function class_exists;
use function implode;
use function in_array;
use function is_float;
use function is_object;
use function is_string;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;

class QueryCheckerTreeWalker extends TreeWalkerAdapter
{

    /**
     * @var WeakReference<LoggerInterface>|null
     */
    private static ?WeakReference $logger = null;

    /**
     * When logger is set, exceptions are logged instead of thrown.
     */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger !== null ? WeakReference::create($logger) : null;
    }

    public function walkSelectStatement(SelectStatement $selectStatement): void
    {
        if ($selectStatement->whereClause !== null) {
            $this->processWhereClause($selectStatement->whereClause);
        }

        if ($selectStatement->havingClause !== null) {
            $this->processHavingClause($selectStatement->havingClause);
        }
    }

    protected function processWhereClause(WhereClause $node): void
    {
        $this->processConditionalExpression($node->conditionalExpression);
    }

    protected function processHavingClause(HavingClause $node): void
    {
        $this->processConditionalExpression($node->conditionalExpression);
    }

    protected function processConditionalExpression(ConditionalExpression|Phase2OptimizableConditional $node): void
    {
        if ($node instanceof ConditionalExpression) {
            foreach ($node->conditionalTerms as $term) {
                $this->processConditionalTerm($term);
            }
        } else {
            $this->processConditionalTerm($node);
        }
    }

    protected function processConditionalTerm(Phase2OptimizableConditional $node): void
    {
        if ($node instanceof ConditionalTerm) {
            foreach ($node->conditionalFactors as $factor) {
                $this->processConditionalFactor($factor);
            }
        } elseif ($node instanceof ConditionalFactor || $node instanceof ConditionalPrimary) {
            $this->processConditionalFactor($node);

        } else {
            throw new LogicException(sprintf('QueryCheckerTreeWalker: Unknown node type: %s', $node::class));
        }
    }

    protected function processConditionalFactor(ConditionalFactor|ConditionalPrimary $node): void
    {
        if ($node instanceof ConditionalFactor) {
            $this->processConditionalPrimary($node->conditionalPrimary);

        } else {
            $this->processConditionalPrimary($node);
        }
    }

    protected function processConditionalPrimary(ConditionalPrimary $node): void
    {
        if ($node->conditionalExpression !== null) {
            $this->processConditionalExpression($node->conditionalExpression);
        }

        if ($node->simpleConditionalExpression !== null) {
            if ($node->simpleConditionalExpression instanceof ComparisonExpression) {
                $this->processComparisonExpression($node->simpleConditionalExpression);
            }
        }
    }

    protected function processComparisonExpression(ComparisonExpression $node): void
    {
        if ($node->leftExpression instanceof ArithmeticExpression && $node->rightExpression instanceof ArithmeticExpression) {
            $this->processComparisonExpressionInner($node->leftExpression, $node->rightExpression);
            $this->processComparisonExpressionInner($node->rightExpression, $node->leftExpression);
        }
    }

    protected function processComparisonExpressionInner(
        ArithmeticExpression $a,
        ArithmeticExpression $b,
    ): void
    {
        if ($a->simpleArithmeticExpression instanceof PathExpression && $b->simpleArithmeticExpression instanceof InputParameter) {
            $this->verifyInputParameterType($a->simpleArithmeticExpression, $b->simpleArithmeticExpression);
        }

        if ($a->subselect !== null) {
            if ($a->subselect->whereClause !== null) {
                $this->processWhereClause($a->subselect->whereClause);
            }

            if ($a->subselect->havingClause !== null) {
                $this->processHavingClause($a->subselect->havingClause);
            }
        }
    }

    protected function verifyInputParameterType(
        PathExpression $pathExpression,
        InputParameter $inputParameter,
    ): void
    {
        $inputParameterType = $this->getInputParameterType($inputParameter);

        if ($inputParameterType === null) {
            return;
        }

        $compatibleTypes = $this->getPathExpressionCompatibleTypes($pathExpression);
        $compatibleTypesExtended = $compatibleTypes;

        foreach ($compatibleTypes as $compatibleType) {
            foreach ($this->extendCompatibleTypes($compatibleType) as $extendedType) {
                $compatibleTypesExtended[] = $extendedType;
            }
        }

        if (in_array($inputParameterType, $compatibleTypesExtended, true)) {
            return;
        }

        $compatibleTypeNames = array_map(self::typeToName(...), $compatibleTypes);

        $this->processException(
            new LogicException(sprintf(
                'QueryCheckerTreeWalker: Parameter "%s" is of type "%s", but expected one of: ["%s"] (because it\'s used in expression with %s)',
                $inputParameter->name,
                self::typeToName($inputParameterType),
                implode('", "', $compatibleTypeNames),
                $pathExpression->field !== null ? "{$pathExpression->identificationVariable}.{$pathExpression->field}" : $pathExpression->identificationVariable,
            )),
        );
    }

    private static function typeToName(string|Type|ParameterType|ArrayParameterType|null $type): string
    {
        if ($type === null) {
            return 'null';
        }

        if (is_string($type)) {
            return $type;
        }

        if ($type instanceof Type) {
            return $type::class;
        }

        return $type::class . '::' . $type->name;
    }

    /**
     * @return list<string|Type|ParameterType|ArrayParameterType>
     */
    protected function getPathExpressionCompatibleTypes(PathExpression $node): array
    {
        if ($node->type === PathExpression::TYPE_STATE_FIELD && $node->field !== null) {
            $classMetadata = $this->getMetadataForDqlAlias($node->identificationVariable);
            return $this->getFieldCompatibleTypes($classMetadata, $node->field);
        }

        if ($node->type === PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION && $node->field !== null) {
            $classMetadata = $this->getMetadataForDqlAlias($node->identificationVariable);
            $targetEntityName = $classMetadata->getAssociationTargetClass($node->field);
            $targetEntityMetadata = $this->getEntityManager()->getClassMetadata($targetEntityName);
            $targetEntityIdentifier = $targetEntityMetadata->getSingleIdentifierFieldName();
            return $this->getFieldCompatibleTypes($targetEntityMetadata, $targetEntityIdentifier);

        }

        throw new LogicException('QueryCheckerTreeWalker: Unknown path expression type');
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return list<string|Type|ParameterType|ArrayParameterType>
     */
    protected function getFieldCompatibleTypes(
        ClassMetadata $classMetadata,
        string $field,
    ): array
    {
        $fieldMapping = $classMetadata->getFieldMapping($field);
        $types = [];

        if ($classMetadata->getSingleIdentifierFieldName() === $field) {
            $types[] = $classMetadata->rootEntityName;
        }

        if ($fieldMapping->enumType !== null) {
            $types[] = $fieldMapping->enumType;
        }

        $types[] = $fieldMapping->type;

        return array_map($this->normalizeType(...), $types);
    }

    protected function getInputParameterType(InputParameter $node): string|Type|ParameterType|ArrayParameterType|null
    {
        $parameter = $this->_getQuery()->getParameter($node->name);

        if ($parameter === null) {
            return null; // happens when the query is analyzed by PHPStan
        }

        if ($parameter->typeWasSpecified()) {
            return $this->normalizeType($parameter->getType());
        }

        if (is_float($parameter->getValue())) {
            return Types::FLOAT; // floats are not inferred by Doctrine\ORM\Query\ParameterTypeInferer::inferType
        }

        if ($parameter->getValue() instanceof BackedEnum) {
            return $parameter->getValue()::class; // more precise than just inferring the underlying type
        }

        if (is_object($parameter->getValue()) && $this->isEntity($parameter->getValue())) {
            $classMetadata = $this->getEntityManager()->getClassMetadata($parameter->getValue()::class);
            return $classMetadata->rootEntityName;
        }

        if ($parameter->getValue() !== null) {
            return $this->normalizeType($parameter->getType());
        }

        return null;
    }

    protected function isEntity(object $object): bool
    {
        $className = $object::class;
        $proxyMarker = '__CG__';
        $markerOffset = strrpos($className, '\\' . $proxyMarker . '\\');
        $realClassName = $markerOffset === false ? $className : substr($className, $markerOffset + strlen($proxyMarker) + 2);

        if (!class_exists($realClassName)) {
            return false;
        }

        return !$this->getEntityManager()->getMetadataFactory()->isTransient($realClassName);
    }

    protected function normalizeType(
        string|Type|ParameterType|ArrayParameterType $type
    ): string|Type|ParameterType|ArrayParameterType
    {
        return match ($type) {
            ParameterType::BOOLEAN => Types::BOOLEAN,
            ParameterType::INTEGER => Types::INTEGER,
            ParameterType::STRING => Types::STRING,
            Types::BIGINT => Types::INTEGER,
            Types::TEXT => Types::STRING,
            default => $type,
        };
    }

    /**
     * @return list<string|Type|ParameterType|ArrayParameterType>
     */
    protected function extendCompatibleTypes(string|Type|ParameterType|ArrayParameterType $type): array
    {
        return match ($type) {
            Types::ASCII_STRING => [Types::STRING],
            Types::FLOAT => [Types::INTEGER, Types::STRING],
            Types::INTEGER => [Types::STRING],
            default => [],
        };
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->_getQuery()->getEntityManager();
    }

    protected function processException(Throwable $e): void
    {
        $logger = self::$logger?->get();

        if ($logger === null) {
            throw $e;
        }

        $logger->error($e->getMessage(), ['exception' => $e]);
    }

}
