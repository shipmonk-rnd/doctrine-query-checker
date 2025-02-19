# Doctrine Query Checker

Doctrine Query Tree Walker that perform additional checks on the query AST in addition to the default checks performed by Doctrine.

Currently it checks that the types of the parameters passed to the query are correct. For example the following will result in exception:

```php
// throws 'Parameter "created_at" is of type "string", but expected "datetime" (because it\'s used in expression with u.createdAt)'
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
