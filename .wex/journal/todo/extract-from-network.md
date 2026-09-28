# Rebuild symfony-data-sync from network's sync engine

Opened: 2026-09-24
Updated: 2026-09-27
Author: agent:archeology, revised with the owner on 2026-09-27

## Status

Written by the 2026-09 network archaeology pass, then discussed and validated with the owner
on 2026-09-27. The decisions below are settled; the steps may start. Stop and report after
step 1 (clean slate plus booting test kernel).

Paths are relative to the PHP suite root (`PACKAGES/PHP/packages/wexample/`), except those
starting with `NETWORK/`, which are relative to `WEXAMPLE/`.

Read `symfony-tunnels/.wex/journal/todo/todo-common.md` first: it holds the conventions every
extraction follows (layout, entities, tests, docs, way of working).

## Goal

Turn `symfony-data-sync` into a generic engine that reconciles local Doctrine entities with
external data sources ("remotes"). It provides declarative definitions per entity, pluggable
adapters, an explicit link store, a matcher, a plan/diff report with dry-run, and synchronous
execution.

The current `src/` (2.0.x) is a verbatim copy of network's 2022 engine. It does not load
(wrong trait namespace) and is coupled to network (`App\...` in the message handler). Keep the
algorithm and the vocabulary, not the code.

## Decisions (validated 2026-09-27)

- **Suite norms first.** Factorization and conventions of the whole stack prevail over this
  todo: `wex ai::design/rules --formatter php-code`, the Rector rules shipped by `symfony-dev`,
  and the mature packages (`symfony-helpers`, `symfony-loader`, `symfony-api`,
  `symfony-design-system`, `symfony-forms`). Search the suite before writing any class
  (`wex app::source/search --scope suite`). Report any divergence rather than following the
  todo silently.
- **Synchronous only in v1.** No Messenger, no queue, no message handler. Sync runs from the
  console and, later, from the diff screen. An async mode (a queue fed from web pages) comes
  back only when an app needs it; the legacy one was dead in prod anyway (no handler
  registered).
- **Link store.** Default: a `SyncLink` entity extending `AbstractEntity` (Uuid id), with the
  local id stored as a string. Alternative: a `PropertyLinkStore` for apps that keep a column
  on the entity (network's `User.rocketChatId`).
- **Matching.** Ordered rule cascade: stored link, then exact normalized fields, then fuzzy.
  Global and one-to-one: ambiguous cases become `Conflict` or `Candidate`, never an
  auto-link. Fuzzy matching is new (the legacy engine had none); it is built **last**, on
  top of a solid exact matcher.
- **Default policies.** Excluded or disabled local: `ignore`. Orphan remote: `report`.
  Unlinked local without match: `report`. No destructive operation and no implicit creation
  unless configured. `RemoteRemove` is never a default.
- **No dependency on `symfony-remote`.** The adapter contract belongs to data sync: a source
  may be an API, a CSV, another database. A `symfony-remote-<service>` package plugs its
  client into this contract.
- **No dependency on `symfony-design-system`.** The engine stays light. The diff screen lives
  in `symfony-data-sync-ds` (see "Package family").
- **Test doubles** (`InMemoryRemoteAdapter`, `InMemoryLocalStore`) ship in `src/Testing/` so
  bridges and apps can reuse them.

## Package family

Naming rule (suite-wide, decided 2026-09-27): `symfony-<parent>-<extension>`. A package that
extends another starts with its parent's name, so each subject forms one block in a listing;
at most one extension suffix. Known extensions: `-ds` (screens on the design system), `-demo`
(demo pages), `-testing`, and `symfony-remote-<service>` for external services.

| Package | Role | Depends on |
|---|---|---|
| `symfony-data-sync` | Engine: definitions, adapters contract, link store, matcher, planner, executor, report, commands. | `symfony-helpers` |
| `symfony-data-sync-ds` | Diff screen (two panes, local / remote per field): confirm candidates, resolve conflicts, apply a plan. Consumes the JSON report. | `symfony-data-sync`, `symfony-design-system` |
| `symfony-data-sync-demo` | Demo pages in `MOJOE/local/design-system`, created by the owner. | `symfony-data-sync-ds` |
| `symfony-remote` | Symfony integration of the generic `php-api` client (`AbstractApiClient`, `ClientOptions` for timeout, retries and rate limit, `checkConnection()`): clients declared in config and injected as services, credentials from env or secrets, health command. Consumes external APIs; `symfony-api` exposes the app's own. The wexample entity layer lives apart in `php-api-entity`. | `php-api` |
| `symfony-remote-ds` | Screens to manage remote connections. | `symfony-remote`, `symfony-design-system` |
| `symfony-remote-rocket-chat` | `RocketChatClient extends AbstractApiClient` (Rocket.Chat does not follow the wexample envelope, so no `php-api-entity`), and the User ↔ Rocket.Chat data-sync adapter. Former proposal: `NETWORK/archeo/proposed-packages/symfony-rocket-chat/todo/extract-from-network.md`. | `symfony-remote`, `symfony-data-sync` |

Renames done on 2026-09-27: `symfony-ds-data-sync` → `symfony-data-sync-ds`,
`symfony-bridge-rocket-chat` → `symfony-remote-rocket-chat`, `symfony-stripe` →
`symfony-payment-stripe` (a payment provider on the Stripe SDK; it may use `symfony-remote`
for credentials and health, but its parent is `payment`).

Nothing Rocket.Chat-specific, and no `App\`, `User`, `Organization` or `SystemLog` reference,
belongs in `symfony-data-sync`.

## Read first

- Knowledge page (algorithm, bugs, design):
  `NETWORK/local/network/.wex/knowledge/readme/archeology/data-sync.md.j2`
- Sources map: `NETWORK/local/network/.wex/knowledge/readme/archeology/sources.md.j2`
- Issues: `NETWORK/archeo/gitlab/issues/104.md` (multi-platform interfacing),
  `227.md` (Rocket.Chat user sync spec), `261.md` (error handling).
- Safety: `NETWORK/local/network` runs on production data. Read its code only, never run
  anything against it, anonymize any fixture, never copy a secret.

## Steps (each ends with green tests and one commit)

1. **Clean slate.** Delete `src/Class/*`, `src/Service/DataSyncManager/*`, `src/Message*`.
   Keep the bundle, the extension and `services.yaml`, widened so autoconfigure covers every
   service directory. Add `phpunit.xml` and a `tests/Fixtures/App/AppKernel` on SQLite,
   modelled on `symfony-loader`. Legacy sources, to read and not copy:
   - `NETWORK/archeo/trees/develop-131-fos-user/src/Wex/BaseBundle/Service/DataSyncManager/EntitiesSyncManager.php`
   - `NETWORK/archeo/trees/develop-131-fos-user/src/Wex/BaseBundle/Service/DataSyncManager/RemoteSyncManager.php`
   - `NETWORK/archeo/trees/develop-131-fos-user/src/Class/EntitySync/`

   **Checkpoint: report to the owner.**
2. **Enums and DTOs.** `Enum/SyncOperation` (LocalCreate, LocalLink, LocalUpdate, LocalUnlink,
   RemoteCreate, RemoteUpdate, RemoteRemove, RemoteDisable, UpToDate, Postponed, Conflict,
   Candidate) with `side()`. `Enum/SyncOutcome` (Success, Error, NothingToDo, Skipped).
   `Class/RemoteItem` (id, fields, raw). Unit tests.
3. **Contracts.** `Interface/RemoteAdapterInterface` (key, list, get, create, update, remove,
   optional disable). `Interface/LocalStoreInterface` with the default
   `Service/DoctrineLocalStore`. `Interface/LinkStoreInterface`.
4. **Link store.** `SyncLink` entity (definition key, local class, local id, remote key,
   remote id, lastSyncedHash, lastSyncedAt), unique on (definition, localId) and
   (definition, remoteId), with its repository. `PropertyLinkStore`. Generate the TS entity
   through the export pipeline (`todo-common.md` §4). Tests: duplicate and orphan-link
   detection.
5. **Test doubles** in `src/Testing/`. Port the fixtures of the lost mock remote
   (`git -C NETWORK/archeo/repo.git show 70cbe29a8^:project/src/Service/DataSyncManager/Remote/MockTestUserRemoteSyncManager.php`):
   remote missing, remote not synced, should update, should remove remote.
6. **Definitions.** `Class/SyncDefinition` plus bundle configuration
   `wexample_symfony_data_sync.definitions.<key>`: `local` class, `adapter` service id,
   `link_store`, `match` rules, `fields` map with per-field `direction`, `local_filter`,
   `remote_exclude` predicates, `orphans.remote` (create_local / ignore / remove_remote /
   report), `orphans.local` (create_remote / ignore / report), `excluded_local` (ignore /
   disable_remote / remove_remote), `conflict` (local_wins / remote_wins / report),
   `thresholds`. `Service/SyncDefinitionRegistry`. Test: configuration parsing and defaults.
7. **Exact matcher.** `Service/Matcher` with `Class/MatchRule/LinkRule` and `ExactFieldRule`
   (normalizers lower, trim, email, slug). Global one-to-one assignment, highest score
   first; ties and many-to-one become `Conflict`. Tests include the legacy bug (remote A
   matches the username, remote B the email: the email rule wins because rules are ordered)
   and case-insensitive email.
8. **Planner.** `Class/SyncPlan` of `Class/SyncRelation` (local side, remote side, operation,
   reason, field diff). Port the legacy decision table (knowledge page, "How it works",
   passes 1 and 2):
   - stale link → `LocalUnlink`;
   - duplicate links → `LocalUnlink` on all but the oldest (not all: legacy bug);
   - unlinked local with a match → `LocalLink`;
   - unlinked local without match → per `orphans.local`;
   - orphan remote → per `orphans.remote`;
   - field diff → `RemoteUpdate` or `LocalUpdate` per field direction;
   - both sides changed since the last hash → `Conflict`.

   Keep "one local mutation per relation per run, the others `Postponed`" and make it
   visible in the report. Deduplicate `LocalCreate` across definitions sharing a local class.
   Tests: one scenario per row, two adapters at once; the legacy "TODO Fails with two"
   (`NETWORK/archeo/trees/develop-131-fos-user/tests/Unit/Sync/RocketChatSyncTest.php::testRecover`)
   must pass.
9. **Single-entity planning.** `planOne(definition, entity)` uses `adapter->get()` and matcher
   lookups, never a full listing. Test with an adapter that throws on `list()`.
10. **Executor.** Synchronous, with `PreOperationEvent` / `PostOperationEvent` and errors
    captured into outcomes (no silent catch). A missing entity or item gives `Skipped`.
11. **Report.** `Class/SyncReport`: counts per operation, per-relation field diffs, conflicts,
    candidates. Renders through `RenderableResponse` (text table and JSON). The JSON is the
    contract `symfony-data-sync-ds` will consume: keep it stable. Snapshot tests.
12. **Commands.** `data-sync:run <definition> [--dry-run] [--local-id=] [--remote-id=]
    [--only=op,...] [--all] [--format=table|json]` (`--only` takes exact operation names;
    the legacy `str_contains` filter was a bug), `data-sync:definitions`,
    `data-sync:link <definition> <localId> <remoteId>`. Tests on the in-memory doubles.
13. **Fuzzy matching.** `Class/MatchRule/FuzzyFieldRule` (Levenshtein ratio, weights) and
    thresholds `auto_link` / `candidate`. Tests: auto-link, candidate, ignore.
14. **Docs.** The four standard knowledge pages and a short README. Cookbook page "Write an
    adapter" with a Rocket.Chat-like example and no SDK.

## Do not

- Copy the legacy classes (`EntitiesSyncManager`, `RemoteSyncManager`, `Map`,
  `RelationPart*`, `FlatDataset`) or keep string operation constants.
- Add Messenger, a queue or a message handler in v1.
- Depend on `symfony-design-system` or `symfony-remote`.
- Recognize remote items by object identity (`===`): key everything by id.
- Key remote sides by adapter class name: two instances of one class must coexist; use the
  definition or adapter key.
- Run anything against network's database or a real Rocket.Chat.

## Acceptance criteria

- `composer install` and the test suite pass; the bundle boots in the test kernel and the
  commands are registered.
- Scenario tests on the in-memory doubles cover: remote create; local create from an orphan
  remote; link by email and by username with rule precedence; stale link unlinked; duplicate
  links (all but one unlinked); field update in each direction; conflict when both sides
  changed; excluded local under each policy; protected remote exclusion (role, name, empty
  email); two adapters on one entity; `LocalCreate` deduplication across definitions;
  dry-run produces no writes.
- `data-sync:run --dry-run --format=json` output is stable (snapshot).
- The fuzzy rule is covered with its thresholds.
