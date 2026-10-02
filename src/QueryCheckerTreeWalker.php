<?php declare(strict_types = 1);

namespace ShipMonk\DoctrineQueryChecker;

use BackedEnum;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\SimpleArrayType;
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
use Doctrine\ORM\Query\AST\InListExpression;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\InSubselectExpression;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\Phase2OptimizableConditional;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\Subselect;
use Doctrine\ORM\Query\AST\WhereClause;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use Psr\Log\LoggerInterface;
use ShipMonk\DoctrineQueryChecker\Exception\LogicException;
use Throwable;
use WeakReference;
use function array_map;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function reset;
use function sprintf;
use function str_contains;
use function str_starts_with;
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

        if ($node->simpleConditionalExpression instanceof ComparisonExpression) {
            $this->processComparisonExpression($node->simpleConditionalExpression);
        }

        if ($node->simpleConditionalExpression instanceof InListExpression) {
            $this->processInListExpression($node->simpleConditionalExpression);
        }

        if ($node->simpleConditionalExpression instanceof InSubselectExpression) {
            $this->processSubselect($node->simpleConditionalExpression->subselect);
        }
    }

    protected function processInListExpression(InListExpression $node): void
    {
        if (!$node->expression->simpleArithmeticExpression instanceof PathExpression) {
            return;
        }

        foreach ($node->literals as $literal) {
            if ($literal instanceof InputParameter && $this->verifyListParameterUsage($literal, listAllowed: true)) {
                $this->verifyInputParameterType($node->expression->simpleArithmeticExpression, $literal);
            }
        }
    }

    protected function processSubselect(Subselect $subselect): void
    {
        if ($subselect->whereClause !== null) {
            $this->processWhereClause($subselect->whereClause);
        }

        if ($subselect->havingClause !== null) {
            $this->processHavingClause($subselect->havingClause);
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
        if (
            $a->simpleArithmeticExpression instanceof PathExpression
            && $b->simpleArithmeticExpression instanceof InputParameter
            && $this->verifyListParameterUsage($b->simpleArithmeticExpression, listAllowed: false)
        ) {
            $this->verifyInputParameterType($a->simpleArithmeticExpression, $b->simpleArithmeticExpression);
        }

        if ($a->subselect !== null) {
            $this->processSubselect($a->subselect);
        }
    }

    /**
     * @return bool False when the usage is wrong. The type check has no meaning then.
     */
    protected function verifyListParameterUsage(
        InputParameter $inputParameter,
        bool $listAllowed,
    ): bool
    {
        $parameter = $this->_getQuery()->getParameter($inputParameter->name);

        if ($parameter === null) {
            return true; // happens when the query is analyzed by PHPStan
        }

        $type = $parameter->getType();

        if ($type instanceof ArrayParameterType) {
            if ($listAllowed) {
                return true;
            }

            $message = $parameter->typeWasSpecified()
                ? "Parameter '{$inputParameter->name}' is using ArrayParameterType in 3rd argument of setParameter()"
                : "Parameter '{$inputParameter->name}' has an array value and no type specified in 3rd argument of setParameter(). Thus it is inferred as a list";

            $message .= ', but it is used outside of IN (...). Doctrine expands a list to one placeholder for each element, which is valid only inside IN (...).';
            $this->processException(new LogicException("QueryCheckerTreeWalker: $message"));
            return false;
        }

        if (is_array($parameter->getValue()) && $this->isScalarType($type)) {
            $typeName = self::typeToName($this->normalizeType($type));
            $message = "Parameter '{$inputParameter->name}' has an array value, but it is using '{$typeName}' type in 3rd argument of setParameter(). Doctrine binds the whole array as one '{$typeName}' value. Use ArrayParameterType to pass a list.";
            $this->processException(new LogicException("QueryCheckerTreeWalker: $message"));
            return false;
        }

        return true;
    }

    /**
     * Custom types are not scalar here, because they can accept an array (e.g. PostgreSQL array types).
     *
     * @phpstan-assert-if-true ParameterType|string $type
     */
    protected function isScalarType(mixed $type): bool
    {
        if ($type instanceof ParameterType) {
            return true;
        }

        if (!is_string($type) || !Type::hasType($type)) {
            return false;
        }

        $dbalType = Type::getType($type);

        return str_starts_with($dbalType::class, 'Doctrine\\DBAL\\Types\\')
            && !str_contains($dbalType::class, 'Json') // JsonObjectType and JsonbType do not exist in DBAL 4.0
            && !$dbalType instanceof SimpleArrayType;
    }

    protected function verifyInputParameterType(
        PathExpression $pathExpression,
        InputParameter $inputParameter,
    ): void
    {
        $parameter = $this->_getQuery()->getParameter($inputParameter->name);

        if ($parameter === null) {
            return; // happens when the query is analyzed by PHPStan
        }

        $parameterType = $this->getParameterType($parameter);

        if ($parameterType === null) {
            return;
        }

        $compatibleTypes = $this->getPathExpressionCompatibleTypes($pathExpression);
        $compatibleTypes = array_map($this->normalizeType(...), $compatibleTypes);
        $compatibleTypesExtended = $compatibleTypes;

        foreach ($compatibleTypes as $compatibleType) {
            foreach ($this->extendCompatibleTypes($compatibleType) as $extendedType) {
                $compatibleTypesExtended[] = $extendedType;
            }
        }

        if (in_array($parameterType, $compatibleTypesExtended, strict: true)) {
            return;
        }

        $parameterTypeName = self::typeToName($parameterType);

        $expressionName = $pathExpression->field !== null
            ? "{$pathExpression->identificationVariable}.{$pathExpression->field}"
            : $pathExpression->identificationVariable;

        $expectedTypeName = count($compatibleTypes) === 1
            ? sprintf("'%s'", self::typeToName($compatibleTypes[0]))
            : sprintf("one of: ['%s']", implode("', '", array_map(self::typeToName(...), $compatibleTypes)));

        $message = $parameter->typeWasSpecified()
            ? "Parameter '{$inputParameter->name}' is using '{$parameterTypeName}' type in 3rd argument of setParameter()"
            : "Parameter '{$inputParameter->name}' has no type specified in 3rd argument of setParameter(). Thus it is inferred as '{$parameterTypeName}'";

        $message .= ", but it is compared with '{$expressionName}' which can only be compared with {$expectedTypeName}.";
        $this->processException(new LogicException("QueryCheckerTreeWalker: $message"));
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

        return $types;
    }

    protected function getParameterType(Parameter $parameter): string|Type|ParameterType|ArrayParameterType|null
    {
        if ($parameter->typeWasSpecified()) {
            return $this->normalizeType($parameter->getType());
        }

        $value = $parameter->getValue();

        if (is_array($value)) {
            // Doctrine binds every element with the type of the first one, so we check that element.
            // We infer its type as a scalar, which is more precise than the ArrayParameterType that Doctrine infers.
            $value = $value === [] ? null : reset($value);
        }

        return $this->getValueType($value);
    }

    protected function getValueType(mixed $value): string|Type|ParameterType|ArrayParameterType|null
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return Types::FLOAT; // floats are not inferred by Doctrine\ORM\Query\ParameterTypeInferer::inferType
        }

        if ($value instanceof BackedEnum) {
            return $value::class; // more precise than just inferring the underlying type
        }

        if (is_object($value) && $this->isEntity($value)) {
            $classMetadata = $this->getEntityManager()->getClassMetadata($value::class);
            return $classMetadata->rootEntityName;
        }

        $inferredType = ParameterTypeInferer::inferType($value);

        if (is_int($inferredType)) {
            return null; // legacy DBAL 3 constant, DBAL 4 never returns it
        }

        return $this->normalizeType($inferredType);
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
        string|Type|ParameterType|ArrayParameterType $type,
    ): string|Type|ParameterType|ArrayParameterType
    {
        return match ($type) {
            ParameterType::BOOLEAN => Types::BOOLEAN,
            ParameterType::INTEGER, ArrayParameterType::INTEGER => Types::INTEGER,
            ParameterType::STRING, ArrayParameterType::STRING => Types::STRING,
            ParameterType::ASCII, ArrayParameterType::ASCII => Types::ASCII_STRING,
            ParameterType::BINARY, ArrayParameterType::BINARY => Types::BINARY,
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
