## Architecture

A run goes through three services, and only the last one writes.

### Definitions

src/DependencyInjection/Configuration.php declares `definitions.<key>`. src/DependencyInjection/WexampleSymfonyDataSyncExtension.php hands the processed configuration to src/Service/SyncDefinitionRegistry.php, with a service locator of the adapters and local stores it names. The registry builds each src/Class/SyncDefinition.php on first use: the adapter, the local store (src/Service/DoctrineLocalStore.php by default), the link store (src/Service/DoctrineLinkStore.php, or a src/Service/PropertyLinkStore.php when `link_property` is set), match rules, field mappings, predicates and policies.

The contracts are small. src/Interface/RemoteAdapterInterface.php speaks src/Class/RemoteItem.php only — an id and fields — so items are always known by id, never by object identity; `DisablableRemoteAdapterInterface` and `SearchableRemoteAdapterInterface` are optional. src/Interface/LocalStoreInterface.php turns entities into src/Class/LocalItem.php. src/Interface/LinkStoreInterface.php remembers pairs as src/Class/LinkRecord.php, with the hash of the values both sides agreed on at the last sync.

### Planning

src/Service/SyncPlanner.php reads everything and writes nothing, returning a src/Class/SyncPlan.php of src/Class/SyncRelation.php.

1. **Links**, oldest first. A link whose item or entity is gone is unlinked; a second link on an already claimed entity or item is a duplicate and is unlinked, the oldest staying. A valid pair is compared field by field: `SyncValueHelper::hash()` of each side's mapped values against the stored hash tells which side changed, and each field's src/Enum/FieldDirection.php plus the definition's conflict policy decide the src/Class/FieldDiff.php target. Every item met by a link is claimed, even when unlinked, so it is not matched again in the same run.
2. **The rest**, excluded items aside, goes to src/Service/Matcher.php. Rules run in order over the whole remaining set: an src/Class/MatchRule/ExactFieldRule.php groups both sides by normalized value and links a value held by exactly one local and one remote; any shared value is a conflict and leaves the pool, so a weaker rule never guesses what a stronger one could not decide. A src/Class/MatchRule/FuzzyFieldRule.php scores pairs (weighted Levenshtein ratio), links above `auto_link`, proposes above `candidate`, and turns ties into conflicts. What is left follows the orphan policies.

`planAll()` then postpones concurrent local writes across definitions: a second update of the same entity, or a second creation for the same identity (the values its exact rules would match on).

`planOne()` plans one entity without listing the remote, through its link or the adapter's search.

### Execution

src/Service/SyncExecutor.php runs relations in order. Before writing it checks that the entity and the item still exist (`skipped` otherwise); after an update it stores the hash of the values both sides now hold. A throwing relation becomes an `error` outcome with its message and the run goes on. `PreOperationEvent` and `PostOperationEvent` are dispatched around each operation. The result is a src/Class/SyncReport.php, whose `toArray()` is the contract of the console JSON and of `symfony-data-sync-ds`.

### Testing

src/Testing/InMemoryRemoteAdapter.php, src/Testing/InMemoryLocalStore.php and src/Testing/InMemoryLinkStore.php ship with the package so applications and adapters test definitions without a network or a database; the in-memory link store accepts duplicates, to seed the corrupted states the planner repairs. The package's own suite runs the legacy scenarios (remote missing, remote not synced, should update, should remove remote), the decision table row by row, and the commands against a fixture kernel.
