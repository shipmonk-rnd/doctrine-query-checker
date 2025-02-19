<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineQueryChecker;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Doctrine\UuidType;
use ShipMonk\DoctrineQueryChecker\Exception\LogicException;
use ShipMonk\DoctrineQueryChecker\QueryCheckerTreeWalker;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntity;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\TestEntityWithManyFieldTypes;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesIntEnum;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesStringEnum;
use ShipMonkTests\DoctrineQueryChecker\Lib\TestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;
use function strtr;

class QueryCheckerTreeWalkerTest extends TestCase
{

    #[DataProvider('provideWrongParameterTypeInVariousPlacesData')]
    public function testWrongParameterTypeInVariousPlaces(mixed $expr): void
    {
        self::assertException(
            LogicException::class,
            'QueryCheckerTreeWalker: Parameter "stringField" is of type "float", but expected "string" (because it\'s used in expression with e.stringField)',
            function () use ($expr): void {
                $this->getEntityManager()->createQueryBuilder()
                    ->select('e')
                    ->from(TestEntityWithManyFieldTypes::class, 'e')
                    ->andWhere($expr)
                    ->setParameter('stringField', 123.4)
                    ->getQuery()
                    ->setQueryCache(new NullAdapter())
                    ->getResult();
            },
        );

        self::assertException(
            LogicException::class,
            'QueryCheckerTreeWalker: Parameter "stringField" is of type "float", but expected "string" (because it\'s used in expression with e.stringField)',
            function () use ($expr): void {
                $this->getEntityManager()->createQueryBuilder()
                    ->select('e')
                    ->from(TestEntityWithManyFieldTypes::class, 'e')
                    ->andHaving($expr)
                    ->setParameter('stringField', 123.4)
                    ->getQuery()
                    ->setQueryCache(new NullAdapter())
                    ->getResult();
            },
        );
    }

    /**
     * @return iterable<array{mixed}>
     */
    public static function provideWrongParameterTypeInVariousPlacesData(): iterable
    {
        $expr = new Expr();
        yield 'eq (left side)' => [$expr->eq('e.stringField', ':stringField')];
        yield 'eq (right side)' => [$expr->eq(':stringField', 'e.stringField')];
        yield 'neq' => [$expr->neq('e.stringField', ':stringField')];
        yield 'lt' => [$expr->lt('e.stringField', ':stringField')];
        yield 'lte' => [$expr->lte('e.stringField', ':stringField')];
        yield 'gt' => [$expr->gt('e.stringField', ':stringField')];
        yield 'gte' => [$expr->gte('e.stringField', ':stringField')];
        yield 'and' => [$expr->andX('1 = 1', $expr->eq('e.stringField', ':stringField'))];
        yield 'or' => [$expr->orX('1 = 1', $expr->eq('e.stringField', ':stringField'))];
        yield 'not' => [$expr->not($expr->eq('e.stringField', ':stringField'))];
    }

    #[DataProvider('provideValidParameterTypesData')]
    public function testValidParameterTypes(
        string $field,
        mixed $parameterValue,
        string|int|null $parameterType = null,
    ): void
    {
        $parameterName = strtr($field, '.', '_');

        /** @var list<TestEntityWithManyFieldTypes> $result */
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('e')
            ->from(TestEntityWithManyFieldTypes::class, 'e')
            ->leftJoin('e.simpleTestEntity', 'se')
            ->leftJoin('e.simpleTestEntityWithUuid', 'sewu')
            ->andWhere($this->getEntityManager()->getExpressionBuilder()->eq($field, ":{$parameterName}"))
            ->setParameter($parameterName, $parameterValue, $parameterType)
            ->getQuery()
            ->setQueryCache(new NullAdapter())
            ->getResult();

        self::assertSame([], $result);
    }

    /**
     * @return iterable<array{0: string, 1: mixed, 2?: string|int|null}>
     */
    public static function provideValidParameterTypesData(): iterable
    {
        yield ['e.stringField', 'ABC'];
        yield ['e.stringField', 'ABC', Types::STRING];

        yield ['e.textField', 'ABC'];
        yield ['e.textField', 'ABC', Types::STRING];
        yield ['e.textField', 'ABC', Types::TEXT];

        yield ['e.floatField', 123.4];
        yield ['e.floatField', 123.4, Types::FLOAT];
        yield ['e.floatField', 123];
        yield ['e.floatField', 123, Types::INTEGER];

        yield ['e.bigintField', 123];
        yield ['e.bigintField', 123, Types::BIGINT];
        yield ['e.bigintField', 123, Types::INTEGER];

        yield ['e.booleanField', true];
        yield ['e.booleanField', true, Types::BOOLEAN];

        yield ['e.jsonField', ['a' => 1], Types::JSON];

        yield ['e.asciiStringField', 'ABC', Types::ASCII_STRING];

        yield ['e.dateTimeImmutableField', new DateTimeImmutable()];
        yield ['e.dateTimeImmutableField', new DateTimeImmutable(), Types::DATETIME_IMMUTABLE];

        yield ['e.intEnumField', TestEntityWithManyFieldTypesIntEnum::A];
        yield ['e.intEnumField', TestEntityWithManyFieldTypesIntEnum::A->value];
        yield ['e.intEnumField', TestEntityWithManyFieldTypesIntEnum::A->value, Types::INTEGER];

        yield ['e.stringEnumField', TestEntityWithManyFieldTypesStringEnum::A];
        yield ['e.stringEnumField', TestEntityWithManyFieldTypesStringEnum::A->value];
        yield ['e.stringEnumField', TestEntityWithManyFieldTypesStringEnum::A->value, Types::STRING];

        $simpleTestEntity = new SimpleTestEntity(1, 'x');
        yield ['e.simpleTestEntity', $simpleTestEntity->getId()];
        yield ['e.simpleTestEntity', $simpleTestEntity->getId(), Types::INTEGER];
        yield ['e.simpleTestEntity', $simpleTestEntity];

        yield ['se', $simpleTestEntity->getId()];
        yield ['se', $simpleTestEntity->getId(), Types::INTEGER];
        yield ['se', $simpleTestEntity];

        yield ['se.id', $simpleTestEntity->getId()];
        yield ['se.id', $simpleTestEntity->getId(), Types::INTEGER];
        yield ['se.id', $simpleTestEntity];

        yield ['se.value', 'x'];
        yield ['se.value', 'x', Types::STRING];

        $simpleTestEntityWithUuid = new SimpleTestEntityWithUuid();
        yield ['e.simpleTestEntityWithUuid', $simpleTestEntityWithUuid->getUuid(), UuidType::NAME];
        yield ['e.simpleTestEntityWithUuid', $simpleTestEntityWithUuid];

        yield ['sewu', $simpleTestEntityWithUuid->getUuid(), UuidType::NAME];
        yield ['sewu', $simpleTestEntityWithUuid];

        yield ['sewu.uuid', $simpleTestEntityWithUuid->getUuid(), UuidType::NAME];
        yield ['sewu.uuid', $simpleTestEntityWithUuid];
    }

    #[DataProvider('provideWrongParameterTypesData')]
    public function testWrongParameterTypes(
        string $field,
        mixed $parameterValue,
        string|int|null $parameterType,
        string $exceptionMessage,
    ): void
    {
        $parameterName = strtr($field, '.', '_');

        self::assertException(
            LogicException::class,
            "QueryCheckerTreeWalker: $exceptionMessage",
            function () use ($parameterName, $field, $parameterValue, $parameterType): void {
                $this->getEntityManager()->createQueryBuilder()
                    ->select('e')
                    ->from(TestEntityWithManyFieldTypes::class, 'e')
                    ->leftJoin('e.simpleTestEntity', 'se')
                    ->leftJoin('e.simpleTestEntityWithUuid', 'sewu')
                    ->andWhere($this->getEntityManager()->getExpressionBuilder()->eq($field, ":{$parameterName}"))
                    ->setParameter($parameterName, $parameterValue, $parameterType)
                    ->getQuery()
                    ->setQueryCache(new NullAdapter())
                    ->getResult();
            },
        );
    }

    /**
     * @return iterable<array{string, mixed, string|int|null, string}>
     */
    public static function provideWrongParameterTypesData(): iterable
    {
        yield [
            'e.stringField',
            123,
            null,
            'Parameter "e_stringField" is of type "integer", but expected "string" (because it\'s used in expression with e.stringField)',
        ];

        yield [
            'e.stringField',
            123.4,
            null,
            'Parameter "e_stringField" is of type "float", but expected "string" (because it\'s used in expression with e.stringField)',
        ];

        yield [
            'e.stringField',
            TestEntityWithManyFieldTypesStringEnum::A,
            null,
            'Parameter "e_stringField" is of type "ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesStringEnum", but expected "string" (because it\'s used in expression with e.stringField)',
        ];

        yield [
            'e.textField',
            123,
            null,
            'Parameter "e_textField" is of type "integer", but expected "string" (because it\'s used in expression with e.textField)',
        ];

        yield [
            'e.textField',
            123.4,
            null,
            'Parameter "e_textField" is of type "float", but expected "string" (because it\'s used in expression with e.textField)',
        ];

        yield [
            'e.booleanField',
            'ABC',
            null,
            'Parameter "e_booleanField" is of type "string", but expected "boolean" (because it\'s used in expression with e.booleanField)',
        ];

        yield [
            'e.stringEnumField',
            TestEntityWithManyFieldTypesIntEnum::A,
            null,
            'Parameter "e_stringEnumField" is of type "ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesIntEnum", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesStringEnum", "string"] (because it\'s used in expression with e.stringEnumField)',
        ];

        yield [
            'e.dateTimeImmutableField',
            '2021-01-01',
            Types::STRING,
            'Parameter "e_dateTimeImmutableField" is of type "string", but expected "datetime_immutable" (because it\'s used in expression with e.dateTimeImmutableField)',
        ];

        $simpleTestEntityWithUuid = new SimpleTestEntityWithUuid();
        yield [
            'e.simpleTestEntity',
            $simpleTestEntityWithUuid,
            null,
            'Parameter "e_simpleTestEntity" is of type "ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntity", "integer"] (because it\'s used in expression with e.simpleTestEntity)',
        ];

        yield [
            'se.id',
            $simpleTestEntityWithUuid,
            null,
            'Parameter "se_id" is of type "ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntity", "integer"] (because it\'s used in expression with se.id)',
        ];

        yield [
            'e.simpleTestEntityWithUuid',
            $simpleTestEntityWithUuid->getUuid(),
            null,
            'Parameter "e_simpleTestEntityWithUuid" is of type "string", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", "uuid"] (because it\'s used in expression with e.simpleTestEntityWithUuid)',
        ];

        yield [
            'e.simpleTestEntityWithUuid',
            $simpleTestEntityWithUuid->getUuid(),
            Types::STRING,
            'Parameter "e_simpleTestEntityWithUuid" is of type "string", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", "uuid"] (because it\'s used in expression with e.simpleTestEntityWithUuid)',
        ];

        yield [
            'sewu',
            $simpleTestEntityWithUuid->getUuid(),
            null,
            'Parameter "sewu" is of type "string", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", "uuid"] (because it\'s used in expression with sewu.uuid)',
        ];

        yield [
            'sewu',
            $simpleTestEntityWithUuid->getUuid(),
            Types::STRING,
            'Parameter "sewu" is of type "string", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", "uuid"] (because it\'s used in expression with sewu.uuid)',
        ];

        yield [
            'sewu.uuid',
            $simpleTestEntityWithUuid->getUuid(),
            null,
            'Parameter "sewu_uuid" is of type "string", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", "uuid"] (because it\'s used in expression with sewu.uuid)',
        ];

        yield [
            'sewu.uuid',
            $simpleTestEntityWithUuid->getUuid(),
            Types::STRING,
            'Parameter "sewu_uuid" is of type "string", but expected one of: ["ShipMonkTests\DoctrineQueryChecker\Fixture\Entity\SimpleTestEntityWithUuid", "uuid"] (because it\'s used in expression with sewu.uuid)',
        ];
    }

    public function testWillUseLoggerIfAvailable(): void
    {
        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'QueryCheckerTreeWalker: Parameter "value" is of type "float", but expected "boolean" (because it\'s used in expression with e.booleanField)',
                self::arrayHasKey('exception'),
            );

        QueryCheckerTreeWalker::setLogger($logger);

        $this->getEntityManager()->createQueryBuilder()
            ->select('e')
            ->from(TestEntityWithManyFieldTypes::class, 'e')
            ->andWhere('e.booleanField = :value')
            ->setParameter('value', 123.4)
            ->getQuery()
            ->setQueryCache(new NullAdapter())
            ->getResult();

        QueryCheckerTreeWalker::setLogger(null);

        self::assertException(
            LogicException::class,
            'QueryCheckerTreeWalker: Parameter "value" is of type "float", but expected "boolean" (because it\'s used in expression with e.booleanField)',
            function (): void {
                $this->getEntityManager()->createQueryBuilder()
                    ->select('e')
                    ->from(TestEntityWithManyFieldTypes::class, 'e')
                    ->andWhere('e.booleanField = :value')
                    ->setParameter('value', 123.4)
                    ->getQuery()
                    ->setQueryCache(new NullAdapter())
                    ->getResult();
            },
        );
    }

}
