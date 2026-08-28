## Architecture

The library ships one Symfony bundle, two abstract base services an application extends, a group of value objects that model a sync run, and a Messenger message pair for async dispatch.

### Bundle and DI wiring

src/WexampleSymfonyDataSyncBundle.php extends `AbstractBundle` from `wexample/symfony-helpers` and carries no logic of its own.

src/DependencyInjection/WexampleSymfonyDataSyncExtension.php loads src/Resources/config/services.yaml, which registers every class under `src/Service/` for autowiring and autoconfiguration. The classes under `src/Class/` are plain PHP objects instantiated directly by the services — they are not Symfony services.

### Services layer

Both abstract base classes live under src/Service/DataSyncManager/EntitiesSyncManager.php and src/Service/DataSyncManager/RemoteSyncManager.php.

#### EntitiesSyncManager

The central orchestrator. It uses `EntityManipulatorTrait` from `wexample/symfony-helpers`, which supplies `getEntityClassName()` so the rest of the library knows which Doctrine entity class is being managed. A concrete subclass must implement one abstract method:

- `buildLocalEntityName(AbstractEntityInterface $entity): string` — a human-readable label used in the `Map` report.

It also owns the `$remoteSyncManagers` array. Application code populates this array (typically via constructor injection in the subclass) with one `RemoteSyncManager` instance per external source to consult.

Its public API exposes two entry points:

- `sync()` — full traversal of all local entities against all registered remote sources.
- `syncSingle()` — identical to `sync()` but filters the resulting `Map` down to a single entity.

`EntitiesSyncManager` defines every operation constant used throughout the library: `OPERATION_LOCAL_CREATE`, `OPERATION_LOCAL_RECOVER`, `OPERATION_LOCAL_REMOVE`, `OPERATION_LOCAL_UPDATE`, `OPERATION_REMOTE_CREATE`, `OPERATION_REMOTE_REMOVE`, `OPERATION_REMOTE_UPDATE`, `OPERATION_UP_TO_DATE`, `OPERATION_NOT_FOUND`, and `OPERATION_POSTPONED`.

#### RemoteSyncManager

Represents one external data source. A concrete subclass must implement the following abstract methods, grouped by concern:

**Look-up**
- `findRemoteItems(): array` — return all items from the remote source.
- `hasRemoteItemForEntity(AbstractEntityInterface $entity): bool`
- `getRemoteItemForEntity(AbstractEntityInterface $entity): mixed`
- `getEntityForRemote(mixed $item): ?AbstractEntityInterface`
- `getRemoteItemById(mixed $id): mixed`
- `getRemoteItemId(mixed $remoteItem): mixed`
- `buildRemoteItemName(mixed $item): string`

**Write operations on the remote side**
- `operationCreateRemoteItem(AbstractEntityInterface $entity): string`
- `operationRemoveRemoteItem(mixed $item): string`

**Write operations on the local side**
- `canCreateLocalEntityFromRemoteItem(mixed $item): bool`
- `operationCreateLocalEntityFromRemote($item): string`
- `operationAttachLocalEntityToRemoteItem(AbstractEntityInterface $entity, mixed $item): string`

Optional overrides include `init(Map $map)` (called before traversal, useful for opening connections), `isRemoteItemMightBeSync()`, `isLocalEntityShouldBeSync()`, `shouldRemoteItemBeUpdatedAccordingLocalEntity()`, `shouldLocalEntityBeUpdatedAccordingRemoteItem()`, and `recoverRemoteItem()` (attempts to match an orphan local entity to an existing remote item).

### Data model

These value objects are constructed during a sync run and hold no Symfony dependencies.

#### Map

src/Class/Map.php is the output of one `sync()` call. It holds the full list of `Relation` objects and the `remoteOrphansSyncMode` string that controls what happens when a remote item has no local counterpart. Helper methods filter the list by operation string (`getFilteredRelations`), strip already-up-to-date entries (`getNonUpToDateRelations`), narrow to a single entity (`applyFilterLocalEntity`), or look up the `RelationPart` for a given entity or remote item.

#### Relation

src/Class/Relation.php pairs one local entity (or placeholder) with its counterparts across every remote source. It holds at most one `RelationPartLocal` and one `RelationPartRemote` per `RemoteSyncManager`. `serialize()` converts the whole relation to a plain array; `EntitiesSyncManager::unserializeRelation()` reconstructs it from that array, which is how the async path reconstructs context inside the message handler.

#### RelationPart family

src/Class/RelationPart.php is the abstract base. It stores the subject object, the pending operation string, the response string written after execution, a back-reference to the parent `Relation`, and the service that owns this side of the pair.

- src/Class/RelationPartLocal.php wraps a Doctrine entity. `getPart()` returns `"local"` and `getObjectId()` returns `$entity->getId()`.
- src/Class/RelationPartRemote.php wraps whatever the remote source returns. `getPart()` returns `"remote"` and `getObjectId()` delegates to `RemoteSyncManager::getRemoteItemId()`.
- src/Class/RelationItemPlaceHolder.php stands in when the real item does not yet exist — for example, the local entity that will be created, or the remote slot to be created. Its label is updated by `RelationPart::setOperation()` so the display always reflects the latest planned operation.

### Async path

src/Message/EntitySyncMessage.php wraps the array produced by `Relation::serialize()`. When `sync()` is called with `$async = true`, every non-up-to-date relation is serialized and dispatched via Symfony Messenger's `MessageBusInterface`. Each part's response is set to `RESPONSE_ENQUEUED` immediately so the returned `Map` can be inspected without waiting.

src/MessageHandler/EntitySyncMessageHandler.php is a Messenger handler (`#[AsMessageHandler]`). It delegates to an application-supplied concrete `EntitiesSyncManager` (`UserEntitiesSyncManager` in the example), which calls `syncMessage()`: that method reconstructs a `Relation` via `unserializeRelation()` and passes it directly to `runRelationsOperations()`.

### Call path through a sync run

1. The caller invokes `EntitiesSyncManager::sync()`.
2. A `Map` is created; `init()` is called on every `RemoteSyncManager` (the hook for opening connections or caching remote collections).
3. **Local-to-remote pass** — `mapLocalToRemote()` loads all Doctrine entities from `buildEntitiesQuery()`. For each entity a `Relation` is added to the `Map`; every `RemoteSyncManager::mapLocalEntity()` inspects the entity and records the required operation on its `RelationPartLocal` and a `RelationPartRemote` (or a placeholder if no remote counterpart exists yet). If a local operation is queued, pending remote operations from other managers are downgraded to `OPERATION_POSTPONED`.
4. **Remote-to-local pass** — `mapRemoteToLocal()` calls `RemoteSyncManager::mapFromAllRemotes()` for every manager. Each remote item is matched to an existing `Relation` if possible; orphans produce new `Relation`s. `mapRemoteItem()` determines the operation on the remote side.
5. The `Map` is optionally filtered by operation string, by a single entity, or reduced to non-up-to-date entries only.
6. **Execution** takes one of two paths:
   - **Synchronous** — `runRelationsOperations()` iterates the relations. When the local part has an operation it calls `executeLocalEntityOperation()` on the responsible service. For each remote part whose operation is still actionable (no blocking local change), it calls `executeRemoteItemOperation()`. Each part's response string is set to the result.
   - **Asynchronous** — each relation is serialized and dispatched as `EntitySyncMessage`; the handler re-runs steps 2 and 6 (compressed into `syncMessage()`) for each message in the queue.
7. `sync()` returns the `Map`, which now carries each part's operation and response string, ready for logging or display.
