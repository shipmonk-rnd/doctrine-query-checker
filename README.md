# Doctrine Query Checker

Doctrine Query Tree Walker that perform additional checks on the query AST in addition to the default checks performed by Doctrine.

Currently it checks that the types of the parameters passed to the query are correct. It checks parameters in comparisons (`=`, `<>`, `<`, ...) and in `IN (...)` lists, including subselects. It also checks that a list parameter is used only inside `IN (...)` and that an array value does not use a scalar type. For example the following will result in exception:

```php
// throws: Parameter 'created_at' has no type specified in 3rd argument of setParameter(). Thus it is inferred as 'string', but it is compared with 'u.createdAt' which can only be compared with 'datetime_immutable'.
$this->entityManager->createQueryBuilder()
    ->select('u')
    ->from(User::class, 'u')
    ->where('u.createdAt < :created_at')
    ->setParameter('created_at', 'not a date')
    ->getQuery()
    ->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [QueryCheckerTreeWalker::class]);
    ->getResult();
```

If you want to log the exceptions instead of throwing them, you can pass a logger to the QueryCheckerTreeWalker:

```php
QueryCheckerTreeWalker::setLogger($logger);
```

### List parameter types

For an untyped array, the checker uses Doctrine's parameter conversion and list type inference. It does not replace the array with its first element. Explicit `ArrayParameterType` values use the same compatibility check. An empty list has no values to check.

List checks compare binding types, not the PHP type of an element. The checker also accepts the column's DBAL binding type. For example, Doctrine binds `[123.4]` as strings, so it passes for a string column. An enum list is checked through its backing values, and an entity list is checked through its IDs. The checker does not validate enum or entity classes within lists, or the format of string-bound date and UUID values. Scalar parameters retain the more precise enum, entity and float checks.

The checker still rejects an integer-bound list for a string column, a list outside `IN (...)`, and an array bound with a scalar type. Use an explicit type for JSON and other array-valued scalar parameters.

## Installation

```bash
composer require shipmonk/doctrine-query-checker
```

## Enabling for a specific query

```php
use Doctrine\ORM\Query;
use ShipMonk\DoctrineQueryChecker\QueryCheckerTreeWalker;

$query = $this->entityManager->createQueryBuilder()
    ->select('u')
    ->from(User::class, 'u')
    ->getQuery()
    ->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [QueryCheckerTreeWalker::class]);
```

## Enabling for all queries

```php
use Doctrine\ORM\Query;
use ShipMonk\DoctrineQueryChecker\QueryCheckerTreeWalker;

$this->entityManager->getConfiguration()
    ->setDefaultQueryHint(Query::HINT_CUSTOM_TREE_WALKERS, [QueryCheckerTreeWalker::class]);
```
