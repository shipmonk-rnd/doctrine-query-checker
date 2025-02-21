<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineQueryChecker\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;

#[Entity]
class SimpleTestEntity
{

    #[Id]
    #[Column(type: Types::INTEGER, nullable: false)]
    private int $id;

    #[Column(type: Types::STRING, length: 255, nullable: false)]
    private string $value;

    public function __construct(
        int $id,
        string $value,
    )
    {
        $this->id = $id;
        $this->value = $value;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

}
