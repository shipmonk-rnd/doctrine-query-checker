<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineQueryChecker\Fixture;

use Doctrine\ORM\EntityManager;
use ShipMonk\DoctrineQueryChecker\QueryCheckerEntityManagerTrait;

final class TestEntityManager extends EntityManager // @phpstan-ignore class.extendsFinalByPhpDoc
{

    use QueryCheckerEntityManagerTrait;

}
