<?php

namespace Wexample\SymfonyDataSync\Enum;

/**
 * What to do with the remote item of a local entity the definition excludes
 * (disabled, archived…).
 */
enum ExcludedLocalPolicy: string
{
    case Ignore = 'ignore';

    /** Keeps the remote account and its history, inactive. */
    case DisableRemote = 'disable_remote';

    /** Destructive: never a default. */
    case RemoveRemote = 'remove_remote';
}
