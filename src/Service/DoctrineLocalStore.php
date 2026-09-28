<?php

namespace Wexample\SymfonyDataSync\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Interface\LocalStoreInterface;

/**
 * The default local store: entities of the definition's class, read and
 * written through their accessors, identified by their Doctrine id.
 */
class DoctrineLocalStore implements LocalStoreInterface
{
    private PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    public function list(SyncDefinition $definition): iterable
    {
        foreach ($this->entityManager->getRepository($definition->localClass)->findBy($definition->localFilter) as $entity) {
            yield $this->toItem($definition, $entity);
        }
    }

    public function find(SyncDefinition $definition, string $id): ?LocalItem
    {
        $entity = $this->entityManager->find($definition->localClass, $id);

        return $entity ? $this->toItem($definition, $entity) : null;
    }

    public function create(SyncDefinition $definition, array $fields): LocalItem
    {
        $entity = new ($definition->localClass)();
        $this->write($entity, $fields);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->toItem($definition, $entity);
    }

    public function update(SyncDefinition $definition, LocalItem $item, array $fields): LocalItem
    {
        $this->write($item->entity, $fields);
        $this->entityManager->flush();

        return $this->toItem($definition, $item->entity);
    }

    private function write(object $entity, array $fields): void
    {
        foreach ($fields as $field => $value) {
            $this->accessor->setValue($entity, $field, $value);
        }
    }

    private function toItem(SyncDefinition $definition, object $entity): LocalItem
    {
        $fields = [];
        foreach ($definition->getLocalFields() as $field) {
            $fields[$field] = $this->accessor->getValue($entity, $field);
        }

        $identifier = $this->entityManager->getClassMetadata($definition->localClass)->getIdentifierValues($entity);

        return new LocalItem(
            implode('-', array_map(static fn (mixed $value): string => (string) $value, $identifier)),
            $fields,
            $entity,
        );
    }
}
