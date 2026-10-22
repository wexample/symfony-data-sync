<?php

namespace Wexample\SymfonyDataSync\Repository;

use Wexample\SymfonyDataSync\Entity\SyncLink;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method SyncLink|null findOneBy(array $criteria, array $orderBy = null)
 * @method SyncLink[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SyncLinkRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return SyncLink::class;
    }

    /**
     * @return SyncLink[] oldest first, which is the order duplicates are resolved in
     */
    public function findByDefinition(string $definitionKey): array
    {
        return $this->findBy(['definitionKey' => $definitionKey], ['dateCreated' => 'ASC']);
    }
}
