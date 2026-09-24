# Rebuild symfony-data-sync from network's sync engine

Opened: 2026-09-24
Updated: 2026-09-24
Author: agent:archeology

## Read this first — status of this todo

> **This is a proposal for discussion, not an order to code.** It was written by the 2026-09 network archaeology pass. Read it, then discuss it with the owner: every design choice and recommendation below is to be challenged and validated **before** any code is written. Do not start implementing on your own.
>
> - Context: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/index.md.j2` (entry point, order between packages), then `sources.md.j2` (where the legacy code lives: archive repo, branch checkouts, GitLab issues) and the domain page linked below.
> - Pending owner decisions affecting this work are listed in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/recap.md.j2`, section "Décisions qui t'attendent". Where this todo assumes an answer, treat it as an open question.
> - Safety: `NETWORK/local/network` runs on **production data** (real bookkeeping, real invoices in `var/`, a prod dump in `.wex/mysql/dumps/`) — read its code only, never run anything against it. Anonymize any fixture taken from network (bank exports, FEC, mails contain real names/accounts). Never copy secrets found in its history (Stripe keys, tokens, passwords, private keys).

## Goal

Turn `wexample/symfony-data-sync` into a generic, configurable engine that reconciles local Doctrine entities with external systems ("remotes"). It must provide declarative definitions per entity, pluggable remote adapters, an explicit link store, a matcher, a plan/diff report with dry-run, and synchronous or Messenger execution.

The current `src/` (2.0.3) is a verbatim copy of network's 2022 engine. It does not load (wrong trait namespace) and it is coupled to network (`App\...` in the message handler). Keep the algorithm and the vocabulary, not the code.

Rocket.Chat is the reference use case, but its adapter does **not** belong here. See `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/proposed-packages/symfony-rocket-chat/todo/extract-from-network.md`.

## Read first

- Knowledge page (algorithm, bugs, design): `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/data-sync.md.j2`
- Sources map: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/sources.md.j2`
- Issues:
  - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/104.md` (multi-platform interfacing)
  - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/227.md` (Rocket.Chat user sync spec)
  - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/261.md` (error handling in async)
- Design rules: run `wex ai::design/rules --formatter php-code` in this package.
- Style reference: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/SERVICES/local/app-board` and `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/PACKAGES/PHP/packages/wexample/symfony-messenger` (queue declaration, `AbstractEntityMessage`).

## Prerequisites / dependencies

- `wexample/symfony-helpers` (>=9): `AbstractBundle`, `RenderableResponse`, `Class/ArrayToTextTable`, `Entity/Traits/Manipulator/EntityManipulatorTrait`, `AbstractRepository`.
- `symfony/messenger` for async. Check whether `wexample/symfony-messenger` should carry the message; ask if unclear.
- No Rocket.Chat SDK and no `App\` reference anywhere in this package.

## Decisions already implied

- Matching is an **ordered rule cascade**: stored link, then exact normalized fields, then an optional fuzzy rule with a float score and thresholds. Matching is **global and one-to-one**: ambiguous cases become `Conflict` or `Candidate` and are never auto-linked. The owner explicitly wants "identify similar content from one database to another".
- Dry-run and a readable diff report are first-class features. The legacy CLI already had `--dry-run`, `--filter`, `--all`, `--entity-id`, `--async`.
- Keep the rule "one local mutation per relation per run; the others become `Postponed`" and the "converges over several runs" behaviour. Make it visible in the report.
- Destructive operations (`RemoteRemove`) are never the default. Excluded or disabled locals default to `ignore` (owner question pending: ignore, disable or remove).

## Steps (each ends with green tests)

1. **Clean slate.** Delete the legacy `src/Class/*`, `src/Service/DataSyncManager/*`, `src/Message*`. Keep the bundle, the extension and `services.yaml` so autoconfigure covers all service directories. Add a `tests/` kernel following another wexample package, e.g. `symfony-messenger/tests`. Legacy sources for reference, to read and not copy:
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/Wex/BaseBundle/Service/DataSyncManager/EntitiesSyncManager.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/Wex/BaseBundle/Service/DataSyncManager/RemoteSyncManager.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/Class/EntitySync/`
2. **Enums and DTOs.**
   - `Enum/SyncOperation`: LocalCreate, LocalLink, LocalUpdate, LocalUnlink, RemoteCreate, RemoteUpdate, RemoteRemove, RemoteDisable, UpToDate, Postponed, Conflict, Candidate. It exposes `side()`.
   - `Enum/SyncOutcome`: Success, Error, NothingToDo, Enqueued, Skipped.
   - `Class/RemoteItem` (id, fields array, raw).
   - Unit tests.
3. **Contracts.**
   - `Interface/RemoteAdapterInterface`: key, list, get, create, update, remove, optional disable.
   - `Interface/LocalStoreInterface` with the default `Service/DoctrineLocalStore`.
   - `Interface/LinkStoreInterface`.
4. **Link store.** Default implementation: a `SyncLink` Doctrine entity (definition key, local class, local id as string, remote key, remote id, lastSyncedHash, lastSyncedAt), unique on (definition, localId) and on (definition, remoteId). Also provide a `PropertyLinkStore` that reads and writes a property on the entity, as network does with `User.rocketChatId`, for apps that keep a column. Tests: duplicate detection, orphan-link detection.
5. **In-memory test doubles** in `tests/` or `src/Testing/`: an `InMemoryRemoteAdapter` and an `InMemoryLocalStore`. Port the fixtures of the lost mock remote (read with `git -C /home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/repo.git show 70cbe29a8^:project/src/Service/DataSyncManager/Remote/MockTestUserRemoteSyncManager.php`): remote missing, remote not synced, should update, should remove remote.
6. **Definitions.**
   - `Class/SyncDefinition` plus bundle configuration `wexample_symfony_data_sync.definitions.<key>` with:
     - `local` class;
     - `adapter` service id;
     - `link_store`;
     - `match` rules;
     - `fields` map with per-field `direction`;
     - `local_filter`;
     - `remote_exclude` predicates;
     - `orphans.remote` (create_local / ignore / remove_remote / report) and `orphans.local` (create_remote / ignore / report);
     - `excluded_local` (ignore / disable_remote / remove_remote);
     - `conflict` (local_wins / remote_wins / report);
     - `thresholds`.
   - `Service/SyncDefinitionRegistry`.
   - Test: parsing the configuration.
7. **Matcher.** `Service/Matcher` plus rules in `Class/MatchRule/`:
   - `LinkRule`;
   - `ExactFieldRule` with normalizers lower, trim, email and slug;
   - `FuzzyFieldRule` with a Levenshtein ratio and weights.

   Global one-to-one assignment: take the highest score first; a tie or a many-to-one case becomes `Conflict`. Tests must include the legacy bug case: remote A matches the local username and remote B matches the local email, and the email rule must win because rules are ordered. Also test case-insensitive email.
8. **Planner.** It produces `Class/SyncPlan` of `Class/SyncRelation` (local side, remote side, operation, reason, field diff). Port the legacy decision table, described in the knowledge page under "How it works" (passes 1 and 2), onto links, the matcher and policies:
   - a stale link becomes `LocalUnlink`;
   - a duplicate link becomes `LocalUnlink` on all but the oldest (**not all**, which was a legacy bug);
   - an unlinked local with a match becomes `LocalLink`;
   - an unlinked local without a match becomes `RemoteCreate` or ignore, per policy;
   - an orphan remote follows the orphan policy;
   - a field diff becomes `RemoteUpdate` or `LocalUpdate` per field direction;
   - a changed hash on both sides becomes `Conflict`.

   When several definitions share one local class, deduplicate `LocalCreate` for the same matched identity (legacy TODO: "if Rocket.Chat and Nextcloud both need new member AA, create one user"). Tests: one scenario per row, and two adapters at once. The legacy "TODO Fails with two" in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/tests/Unit/Sync/RocketChatSyncTest.php::testRecover` must pass.
9. **Single-entity planning.** `planOne(definition, entity)` uses `adapter->get()` and matcher lookups and never lists every remote (legacy `syncSingle` listed everything inside the web request). Test with an adapter that throws on `list()`.
10. **Executor.** Synchronous execution, with `PreOperationEvent` and `PostOperationEvent` and error capture into outcomes (no silent catch). Async execution: one Messenger message per relation (definition key, local id, remote id, operation, plan hash), and a generic handler in this package that re-plans that relation and executes it only when the plan is unchanged.
    - A missing entity or item gives `Skipped`.
    - Transient adapter errors are raised as `RecoverableMessageHandlingException` (#261).
    - Make sure the handler is registered: the legacy bug was that prod had no handler at all.

    Tests: sync mode, async mode with the in-memory transport, and missing entity.
11. **Report.** `Class/SyncReport`: counts per operation, per-relation field diffs, conflicts and candidates. Renders through `RenderableResponse` (text table and JSON). It replaces `FlatDataset` and `Map::toFlatDataset`. Snapshot tests.
12. **Commands.**
    - `data-sync:run <definition> [--dry-run] [--async] [--local-id=] [--remote-id=] [--only=op,...] [--all] [--format=table|json]`. `--only` takes exact operation names; the legacy `str_contains` filter was a bug.
    - `data-sync:definitions`.
    - `data-sync:link <definition> <localId> <remoteId>`, to resolve candidates and conflicts manually.

    Command tests on the in-memory adapter.
13. **Docs.** Update `.wex/knowledge/**` and the README (the generated README still describes the legacy classes). Add a cookbook page "Write an adapter" that uses a Rocket.Chat-like example with no SDK.

## Do not

- Do not copy the legacy classes as they are (`EntitiesSyncManager`, `RemoteSyncManager`, `Map`, `RelationPart*`, `FlatDataset`). Do not keep string operation constants.
- Do not reference `App\`, Rocket.Chat, `User`, `Organization` or `SystemLog` in this package.
- Do not make `RemoteRemove` a default of any policy. Do not delete remote accounts when a local entity is disabled unless it is configured.
- Do not rely on object identity (`===`) to recognize remote items; key everything by id.
- Do not key remote sides by adapter class name (two instances of one class must be able to coexist); use the definition or adapter key.
- Do not run anything against network's database or a real Rocket.Chat. Do not copy credentials from network's `.env.local`.

## Acceptance criteria

- `composer install` and the package test suite pass. The bundle boots in the test kernel, and the handler and commands are registered.
- Scenario tests on in-memory doubles cover:
  - remote create;
  - local create from an orphan remote;
  - link by email and link by username, with rule precedence;
  - stale link unlinked;
  - duplicate links (all but one unlinked);
  - field update in each direction;
  - conflict when both sides changed;
  - excluded local under each policy;
  - protected remote exclusion (role, name, empty email);
  - two adapters on one entity (the former "fails with two");
  - `LocalCreate` deduplication across definitions;
  - dry-run produces no writes;
  - async round-trip.
- The `data-sync:run --dry-run --format=json` output is stable (snapshot).
- The fuzzy rule is covered with thresholds: auto-link, candidate and ignore.
