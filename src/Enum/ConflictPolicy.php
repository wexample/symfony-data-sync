<?php

namespace Wexample\SymfonyDataSync\Enum;

/**
 * Who wins a field both sides changed since the last sync, or that differs
 * on a pair never synced before.
 */
enum ConflictPolicy: string
{
    case LocalWins = 'local_wins';
    case RemoteWins = 'remote_wins';
    case Report = 'report';
}
