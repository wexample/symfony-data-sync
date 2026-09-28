<?php

namespace Wexample\SymfonyDataSync\Enum;

enum SyncOutcome: string
{
    case Success = 'success';
    case Error = 'error';

    /** The operation only reports, or the run was a dry run. */
    case NothingToDo = 'nothing_to_do';

    /** The entity or item vanished between planning and execution. */
    case Skipped = 'skipped';
}
