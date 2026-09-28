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

`planOne()` never lists the remote: it follows the entity's link, or searches the adapter when it implements `SearchableRemoteAdapterInterface`. Otherwise an unlinked entity waits for the next full run rather than risk a duplicate.
