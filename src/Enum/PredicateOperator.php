<?php

namespace Wexample\SymfonyDataSync\Enum;

enum PredicateOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';

    /** The value is one of a list. */
    case In = 'in';

    /** A list field holds the value, or a string field includes it. */
    case Contains = 'contains';

    /** Null, empty string or empty list. */
    case Empty = 'empty';
}
