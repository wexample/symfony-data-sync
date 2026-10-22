<?php

namespace Wexample\SymfonyDataSync\Enum;

/**
 * Where an operation writes: the application's own data, the remote, or
 * nowhere (the operation only reports).
 */
enum SyncSide: string
{
    case Local = 'local';
    case Remote = 'remote';
    case None = 'none';
}
