<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineQueryChecker\Fixture\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesIntEnum;
use ShipMonkTests\DoctrineQueryChecker\Fixture\Enum\TestEntityWithManyFieldTypesStringEnum;

#[Entity]
class TestEntityWithManyFieldTypes
{

    #[Id]
    #[Column(type: Types::INTEGER, nullable: false)]
    private int $id;

    #[Column(type: Types::STRING, length: 255, nullable: false)]
    private string $stringField;

    #[Column(type: Types::TEXT, nullable: false)]
    private string $textField;

    #[Column(type: Types::FLOAT, nullable: false)]
    private float $floatField;

    #[Column(type: Types::BIGINT, nullable: false)]
    private int $bigintField;

    #[Column(type: Types::BOOLEAN, nullable: false)]
    private bool $booleanField;

    /**
     * @var array<mixed>
     */
    #[Column(type: Types::JSON, nullable: false)]
    private array $jsonField;

    #[Column(type: Types::ASCII_STRING, length: 255, nullable: false)]
    private string $asciiStringField;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $dateTimeImmutableField;

    #[Column(type: Types::INTEGER, enumType: TestEntityWithManyFieldTypesIntEnum::class, nullable: false)]
    private TestEntityWithManyFieldTypesIntEnum $intEnumField;

    #[Column(type: Types::STRING, enumType: TestEntityWithManyFieldTypesStringEnum::class, nullable: false)]
    private TestEntityWithManyFieldTypesStringEnum $stringEnumField;

    #[ManyToOne(targetEntity: SimpleTestEntity::class)]
    #[JoinColumn(nullable: true)]
    private ?SimpleTestEntity $simpleTestEntity;

    #[ManyToOne(targetEntity: SimpleTestEntityWithUuid::class)]
    #[JoinColumn(nullable: true, referencedColumnName: 'uuid')]
    private ?SimpleTestEntityWithUuid $simpleTestEntityWithUuid;

    /**
     * @param array<mixed> $jsonField
     */
    public function __construct(
        int $id,
        string $stringField,
        string $textField,
        float $floatField,
        int $bigintField,
        bool $booleanField,
        array $jsonField,
        string $asciiStringField,
        DateTimeImmutable $dateTimeImmutableField,
        TestEntityWithManyFieldTypesIntEnum $intEnumField,
        TestEntityWithManyFieldTypesStringEnum $stringEnumField,
        ?SimpleTestEntity $simpleTestEntity,
        ?SimpleTestEntityWithUuid $simpleTestEntityWithUuid,
    )
    {
        $this->id = $id;
        $this->stringField = $stringField;
        $this->textField = $textField;
        $this->floatField = $floatField;
        $this->bigintField = $bigintField;
        $this->booleanField = $booleanField;
        $this->jsonField = $jsonField;
        $this->asciiStringField = $asciiStringField;
        $this->dateTimeImmutableField = $dateTimeImmutableField;
        $this->intEnumField = $intEnumField;
        $this->stringEnumField = $stringEnumField;
        $this->simpleTestEntity = $simpleTestEntity;
        $this->simpleTestEntityWithUuid = $simpleTestEntityWithUuid;
    }

    public function getId(): int
    {
        return $this->id;
    }

}
