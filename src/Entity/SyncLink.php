<?php

namespace Wexample\SymfonyDataSync\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\Pseudocode\Attribute\PseudocodeExport;
use Wexample\SymfonyDataSync\Repository\SyncLinkRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Entity\Traits\HasDateCreatedTrait;

/**
 * One local entity known as one remote item, for one definition. The unique
 * constraints make a duplicate or a many-to-one link impossible to store.
 */
#[ORM\Entity(repositoryClass: SyncLinkRepository::class)]
#[ORM\Table(name: 'sync_link')]
#[ORM\UniqueConstraint(name: 'sync_link_local', columns: ['definition_key', 'local_id'])]
#[ORM\UniqueConstraint(name: 'sync_link_remote', columns: ['definition_key', 'remote_id'])]
#[PseudocodeExport(inherited: true)]
class SyncLink extends AbstractEntity
{
    use HasDateCreatedTrait;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $definitionKey;

    /**
     * @var class-string
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $localClass;

    /**
     * Stored as a string: an int, a Uuid or a composite key all fit.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $localId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $remoteId;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    protected ?string $lastSyncedHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $lastSyncedAt = null;

    public function __construct(
        string $definitionKey,
        string $localClass,
        string $localId,
        string $remoteId,
    ) {
        parent::__construct();

        $this->definitionKey = $definitionKey;
        $this->localClass = $localClass;
        $this->localId = $localId;
        $this->remoteId = $remoteId;
        $this->setDateCreatedNow();
    }

    public function getDefinitionKey(): string
    {
        return $this->definitionKey;
    }

    public function getLocalClass(): string
    {
        return $this->localClass;
    }

    public function getLocalId(): string
    {
        return $this->localId;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getLastSyncedHash(): ?string
    {
        return $this->lastSyncedHash;
    }

    public function getLastSyncedAt(): ?DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function markSynced(string $hash): self
    {
        $this->lastSyncedHash = $hash;
        $this->lastSyncedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->getDateCreated();
    }
}
