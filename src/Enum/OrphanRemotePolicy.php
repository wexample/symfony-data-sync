<?php

namespace Wexample\SymfonyDataSync\Enum;

/**
 * What to do with a remote item no local entity is linked or matched to.
 */
enum OrphanRemotePolicy: string
{
    case CreateLocal = 'create_local';
    case Ignore = 'ignore';

    /** Destructive: never a default. */
    case RemoveRemote = 'remove_remote';
    case Report = 'report';
}
