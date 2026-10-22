<?php

namespace Wexample\SymfonyDataSync\Enum;

enum SyncOperation: string
{
    /** Create the local entity of an orphan remote item. */
    case LocalCreate = 'local_create';

    /** Record that a local entity and a remote item are the same thing. */
    case LocalLink = 'local_link';

    /** Write remote values into the local entity (and push local ones, see SyncRelation). */
    case LocalUpdate = 'local_update';

    /** Forget a link: its remote item or local entity is gone, or it is a duplicate. */
    case LocalUnlink = 'local_unlink';

    case RemoteCreate = 'remote_create';

    /** Write local values into the remote item. */
    case RemoteUpdate = 'remote_update';

    case RemoteRemove = 'remote_remove';

    case RemoteDisable = 'remote_disable';

    case UpToDate = 'up_to_date';

    /** Deferred to a next run, another relation writing the same local entity first. */
    case Postponed = 'postponed';

    /** Both sides changed, or several items claim each other: a human decides. */
    case Conflict = 'conflict';

    /** A fuzzy match below the auto-link threshold: a human confirms. */
    case Candidate = 'candidate';

    /** Nothing matched and the policy says report: listed, left alone. */
    case Unmatched = 'unmatched';

    public function side(): SyncSide
    {
        return match ($this) {
            self::LocalCreate, self::LocalLink, self::LocalUpdate, self::LocalUnlink => SyncSide::Local,
            self::RemoteCreate, self::RemoteUpdate, self::RemoteRemove, self::RemoteDisable => SyncSide::Remote,
            default => SyncSide::None,
        };
    }

    /**
     * Whether running the plan does something for this operation.
     */
    public function isAction(): bool
    {
        return SyncSide::None !== $this->side();
    }
}
