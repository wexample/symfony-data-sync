<?php

namespace Wexample\SymfonyDataSync\Enum;

enum FieldDirection: string
{
    /** The application owns the value: it is pushed, never pulled. */
    case LocalToRemote = 'local_to_remote';

    /** The remote owns the value: it is pulled, never pushed. */
    case RemoteToLocal = 'remote_to_local';

    /** Whichever side changed since the last sync wins; both changed is a conflict. */
    case Both = 'both';

    public function pushes(): bool
    {
        return self::RemoteToLocal !== $this;
    }

    public function pulls(): bool
    {
        return self::LocalToRemote !== $this;
    }
}
