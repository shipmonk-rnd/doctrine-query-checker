<?php declare(strict_types = 1);

namespace ShipMonk\DoctrineQueryChecker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * @mixin EntityManagerInterface
 */
trait QueryCheckerEntityManagerTrait
{

    public function createQuery(string $dql = ''): Query
    {
        $query = parent::createQuery($dql);

        $existingCustomTreeWalkers = $query->hasHint(Query::HINT_CUSTOM_TREE_WALKERS)
            ? $query->getHint(Query::HINT_CUSTOM_TREE_WALKERS)
            : [];

        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [
            ...$existingCustomTreeWalkers,
            QueryCheckerTreeWalker::class,
        ]);

        return $query;
    }

}
