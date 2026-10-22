<?php

namespace Wexample\SymfonyDataSync\Enum;

/**
 * What to do with a local entity no remote item is linked or matched to.
 */
enum OrphanLocalPolicy: string
{
    case CreateRemote = 'create_remote';
    case Ignore = 'ignore';
    case Report = 'report';
}
