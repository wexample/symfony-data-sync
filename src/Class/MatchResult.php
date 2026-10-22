<?php

namespace Wexample\SymfonyDataSync\Class;

/**
 * What the matcher decided for a set of unlinked locals and remotes.
 */
final class MatchResult
{
    /**
     * @var array<string, array{remoteId: string, rule: string, score: float}> by local id
     */
    public array $matches = [];

    /**
     * @var array<int, array{localIds: string[], remoteIds: string[], rule: string}>
     */
    public array $conflicts = [];

    /**
     * @var array<int, array{localId: string, remoteId: string, rule: string, score: float}>
     */
    public array $candidates = [];
}
