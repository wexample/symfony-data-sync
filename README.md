# symfony_data_sync

Version: 3.0.1

## A definition

```yaml
# config/packages/wexample_symfony_data_sync.yaml
wexample_symfony_data_sync:
  definitions:
    chat_users:
      local: App\Entity\User
      adapter: App\Sync\ChatUserAdapter           # a RemoteAdapterInterface service
      local_filter: { organization: ~ }           # Doctrine criteria, optional
      match:                                      # tried in order, after stored links
        - { local: email, remote: email, normalize: [email] }
        - { local: username, remote: username, normalize: [lower] }
        - { type: fuzzy, fields: [{ local: displayName, remote: name }] }
      fields:
        username: username                        # pushed (local_to_remote) by default
        displayName: { remote: name, direction: both }
      local_exclude:                              # kept linked, handled per excluded_local
        - { field: enabled, operator: equals, value: false }
      remote_exclude:                             # never touched
        - { field: roles, operator: contains, value: bot }
        - { field: username, operator: in, value: [admin] }
        - { field: email, operator: empty }
      orphans: { remote: report, local: create_remote }
      excluded_local: disable_remote
      conflict: report
      thresholds: { auto_link: 0.95, candidate: 0.7 }
```

Every policy defaults to what cannot hurt: `orphans.remote: report`, `orphans.local: report`, `excluded_local: ignore`, `conflict: report`. `remove_remote` exists for both, and is never a default.

Field directions: `local_to_remote` pushes, `remote_to_local` pulls, `both` follows the side that changed since the last sync. When both changed — or on a pair never synced, whose history is unknown — `conflict` decides: `local_wins`, `remote_wins`, or `report`.

## Running

```bash
bin/console data-sync:definitions
bin/console data-sync:run chat_users --dry-run            # the plan, nothing written
bin/console data-sync:run chat_users                      # plan and run
bin/console data-sync:run                                 # every definition
bin/console data-sync:run chat_users --only=remote_create,local_link --format=json
bin/console data-sync:run chat_users --local-id=42        # one entity, without listing the remote
bin/console data-sync:link chat_users 42 abc123           # settle a candidate or a conflict by hand
```

`--only` takes exact operation names. `--all` also shows up-to-date pairs. The command fails when an operation failed.

A sync converges over runs rather than in one: an entity unlinked in a run (its item is gone, or the link was a duplicate) is matched again in the next one; when two definitions write the same local entity, or would create the same person twice, the second waits (`postponed`) and finds the first's result next time.

## Operations

| Operation | Written | When |
|---|---|---|
| `local_link` | link store | an unlinked pair matched |
| `local_unlink` | link store | the item or entity is gone; a duplicate link (the oldest is kept) |
| `local_create` / `remote_create` | local / remote, then a link | an orphan, when its policy creates |
| `local_update` / `remote_update` | both sides as needed | mapped fields differ; an update pushes and pulls its fields at once |
| `remote_disable` / `remote_remove` | remote | an excluded local, or an orphan remote, per policy |
| `conflict` | nothing | both sides changed, or several items share a matching value |
| `candidate` | nothing | a fuzzy score between the two thresholds |
| `unmatched` | nothing | no counterpart, and the policy reports |
| `postponed` | nothing | another definition writes the same local entity first |
| `up_to_date` | the link's hash, if missing | both sides agree |

## Planning one entity from code

```php
$definition = $registry->get('chat_users');
$plan = $planner->planOne($definition, $definition->localStore->find($definition, (string) $user->getId()));
$report = $executor->execute($plan, ['chat_users' => $definition]);
```

A field conflict (`report` policy) waits for a human: `SyncResolver::resolve($key, $localId, SyncSide::Local)` keeps the application's values, `SyncSide::Remote` the remote's; the screens of `symfony-data-sync-ds` offer both. With `link_property`, which keeps no history, a `both` field that differs is always a conflict.

`planOne()` never lists the remote: it follows the entity's link, or searches the adapter when it implements `SearchableRemoteAdapterInterface`. Otherwise an unlinked entity waits for the next full run rather than risk a duplicate.

## Table of Contents

- [A definition](#a-definition)
- [Running](#running)
- [Operations](#operations)
- [Planning one entity from code](#planning-one-entity-from-code)
- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

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

src/Service/SyncExecutor.php runs relations in order. Before writing it checks that the entity and the item still exist (`skipped` otherwise); after an update it stores the hash of the values both sides now hold. A creation writes every mapped field, whatever its direction: directions govern later updates, and a pulled field left out would come back empty and erase the local value. A throwing relation becomes an `error` outcome with its message and the run goes on. `PreOperationEvent` and `PostOperationEvent` are dispatched around each operation. The result is a src/Class/SyncReport.php, whose `toArray()` is the contract of the console JSON and of `symfony-data-sync-ds`.

### Around the planner

src/Service/SyncRunner.php plans and runs definitions by key with the filters of `data-sync:run`; src/Service/SyncLinker.php links a pair by hand after checking both exist and neither is linked; src/Service/SyncResolver.php settles a pair's field conflict by the side a human keeps, writing its values on the other side. The commands and `symfony-data-sync-ds` share these three.

### Testing

src/Testing/InMemoryRemoteAdapter.php, src/Testing/InMemoryLocalStore.php and src/Testing/InMemoryLinkStore.php ship with the package so applications and adapters test definitions without a network or a database; the in-memory link store accepts duplicates, to seed the corrupted states the planner repairs. The package's own suite runs the legacy scenarios (remote missing, remote not synced, should update, should remove remote), the decision table row by row, and the commands against a fixture kernel.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- php: >=8.5
- wexample/symfony-helpers: >=13.0.0
- doctrine/orm: ^3.0
- symfony/property-access: *
- symfony/string: *

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
