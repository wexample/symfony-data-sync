## Write an adapter

An adapter translates one service's API into `RemoteItem`. It knows nothing of the application's entities: mapping and matching belong to the definition.

A users adapter for a chat server, written against a plain HTTP client:

```php
final readonly class ChatUserAdapter implements DisablableRemoteAdapterInterface, SearchableRemoteAdapterInterface
{
    public function __construct(private ChatClient $client) {}

    public function list(): iterable
    {
        foreach ($this->client->listUsers() as $user) {   // paginated by the client
            yield $this->toItem($user);
        }
    }

    public function get(string $id): ?RemoteItem
    {
        $user = $this->client->findUser($id);            // null when the server answers 404

        return $user ? $this->toItem($user) : null;
    }

    public function findBy(string $field, mixed $value): iterable
    {
        // Compare as the server does: its email lookup ignores case.
        if ('email' === $field && $user = $this->client->findUserByEmail((string) $value)) {
            yield $this->toItem($user);
        }
    }

    public function create(array $fields): RemoteItem
    {
        // A random password the user must change: never a local password hash.
        return $this->toItem($this->client->createUser($fields['username'], $fields['email'], $fields['name'] ?? '', bin2hex(random_bytes(16))));
    }

    public function update(string $id, array $fields): RemoteItem
    {
        return $this->toItem($this->client->updateUser($id, $fields));
    }

    public function remove(string $id): void
    {
        $this->client->deleteUser($id);
    }

    public function disable(string $id): void
    {
        $this->client->setActive($id, false);            // keeps the account and its history
    }

    private function toItem(array $user): RemoteItem
    {
        return new RemoteItem($user['_id'], [
            'username' => $user['username'],
            'email' => $user['emails'][0]['address'] ?? null,
            'name' => $user['name'] ?? null,
            'roles' => $user['roles'] ?? [],
            'active' => $user['active'] ?? true,
        ], $user);
    }
}
```

Guidelines:

- Put in `fields` everything a definition may map, match or exclude on — roles and active flags included — flattened to scalars and lists.
- Let errors raise: the executor records them per relation with their message.
- `list()` may be a generator: the planner reads it once.
- Test the definition with `InMemoryRemoteAdapter` first, then the adapter against its client's mocked HTTP.
