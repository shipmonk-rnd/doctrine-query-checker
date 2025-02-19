<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineQueryChecker\Lib;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Exception as PhpUnitException;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Ramsey\Uuid\Doctrine\UuidType;
use ShipMonk\DoctrineQueryChecker\QueryCheckerTreeWalker;
use Throwable;

abstract class TestCase extends PhpUnitTestCase
{

    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = null;
    }

    /**
     * @template T of Throwable
     * @param class-string<T> $type
     * @param callable(): mixed $cb
     * @param-immediately-invoked-callable $cb
     */
    protected static function assertException(string $type, ?string $message, callable $cb): void
    {
        try {
            $cb();
            self::fail("Expected exception of type {$type} to be thrown");

        } catch (Throwable $e) {
            if ($e instanceof PhpUnitException) {
                throw $e;
            }

            self::assertInstanceOf($type, $e);

            if ($message !== null) {
                self::assertStringMatchesFormat($message, $e->getMessage());
            }
        }
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager ??= $this->createEntityManager();
    }

    private function createEntityManager(): EntityManagerInterface
    {
        if (!Type::hasType(UuidType::NAME)) {
            Type::addType(UuidType::NAME, UuidType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Fixture'], isDevMode: true, proxyDir: __DIR__ . '/../../cache/proxies');
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->setDefaultQueryHint(Query::HINT_CUSTOM_TREE_WALKERS, [QueryCheckerTreeWalker::class]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $schemaValidator = new SchemaValidator($entityManager);
        $schemaValidator->validateMapping();

        return $entityManager;
    }

}
