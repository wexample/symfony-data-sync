# symfony_data_sync

Version: 3.1.3

Reconciles local Doctrine entities with external data sources: definitions, adapters, links, matching, plans and dry runs

## Table of Contents

- [Installation](#installation)
- [Introduction](#introduction)

## Installation

```php
// config/bundles.php
Wexample\SymfonyDataSync\WexampleSymfonyDataSyncBundle::class => ['all' => true],
```

The default link store is a `sync_link` table (entity `Wexample\SymfonyDataSync\Entity\SyncLink`, mapped automatically): generate the migration with `doctrine:migrations:diff`. A definition may keep the remote id in a column of its entity instead, with `link_property`.

Synchronization is synchronous: `data-sync:run` from the console or a cron. There is no queue in this version.

`symfony-data-sync` keeps Doctrine entities in step with external data sources — a chat server's users, a cloud's accounts, a CRM's organizations. Each pairing is a *definition*: a local class, a remote adapter, the rules that recognize an entity and an item as the same thing, the fields that travel and in which direction, and what to do with what has no counterpart.

A run is planned before it writes anything: every decision — link, create, update, unlink, conflict, candidate — is a relation of a plan that `--dry-run` prints and `symfony-data-sync-ds` will show. Links are stored explicitly, so an entity and an item are never recognized by chance twice; matching is global and one to one, so ambiguity becomes a conflict for a human, never a guess. Defaults report and never destroy: removing or creating anything is a policy the application writes down.

Adapters know their service and nothing of the application: `symfony-remote-rocket-chat` is one. The package ships in-memory doubles so definitions are tested without a network or a database.
