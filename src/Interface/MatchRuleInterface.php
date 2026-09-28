<?php

namespace Wexample\SymfonyDataSync\Interface;

use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\RemoteItem;

/**
 * One way of recognizing that a local entity and a remote item are the same.
 * Rules are tried in the definition's order; the first that decides wins.
 */
interface MatchRuleInterface
{
    /**
     * @return float between 0 (different) and 1 (certainly the same)
     */
    public function score(LocalItem $local, RemoteItem $remote): float;

    /**
     * The local fields the rule reads, so the local store loads them.
     *
     * @return string[]
     */
    public function getLocalFields(): array;

    public function describe(): string;
}
