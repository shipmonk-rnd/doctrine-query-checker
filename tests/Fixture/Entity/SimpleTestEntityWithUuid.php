<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineQueryChecker\Fixture\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class SimpleTestEntityWithUuid
{

    #[Id]
    #[Column(type: UuidType::NAME, nullable: false)]
    private UuidInterface $uuid;

    public function __construct()
    {
        $this->uuid = Uuid::uuid7();
    }

    public function getUuid(): UuidInterface
    {
        return $this->uuid;
    }

}
