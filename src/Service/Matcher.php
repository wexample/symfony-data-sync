<?php

namespace Wexample\SymfonyDataSync\Service;

use Wexample\SymfonyDataSync\Class\LocalItem;
use Wexample\SymfonyDataSync\Class\MatchResult;
use Wexample\SymfonyDataSync\Class\MatchRule\ExactFieldRule;
use Wexample\SymfonyDataSync\Class\RemoteItem;
use Wexample\SymfonyDataSync\Class\SyncDefinition;
use Wexample\SymfonyDataSync\Interface\MatchRuleInterface;

/**
 * Pairs unlinked locals and remotes, one to one, rule after rule in the
 * definition's order. Each rule sees the whole remaining set at once, so an
 * earlier rule always wins over a later one, whatever the items' order.
 * Anything ambiguous leaves the pool as a conflict: a later, weaker rule must
 * not guess what a stronger one could not decide.
 */
class Matcher
{
    /**
     * @param LocalItem[] $locals
     * @param RemoteItem[] $remotes
     */
    public function match(SyncDefinition $definition, array $locals, array $remotes): MatchResult
    {
        $result = new MatchResult();
        $locals = $this->byId($locals);
        $remotes = $this->byId($remotes);

        foreach ($definition->matchRules as $rule) {
            if ([] === $locals || [] === $remotes) {
                break;
            }

            $rule instanceof ExactFieldRule
                ? $this->matchExact($rule, $locals, $remotes, $result)
                : $this->matchScored($definition, $rule, $locals, $remotes, $result);
        }

        return $result;
    }

    /**
     * @param array<string, LocalItem> $locals remaining, reduced in place
     * @param array<string, RemoteItem> $remotes remaining, reduced in place
     */
    private function matchExact(ExactFieldRule $rule, array &$locals, array &$remotes, MatchResult $result): void
    {
        $localsByKey = $this->group($locals, $rule->localKey(...));
        $remotesByKey = $this->group($remotes, $rule->remoteKey(...));

        foreach (array_intersect_key($localsByKey, $remotesByKey) as $key => $localIds) {
            $remoteIds = $remotesByKey[$key];

            if (1 === count($localIds) && 1 === count($remoteIds)) {
                $result->matches[$localIds[0]] = ['remoteId' => $remoteIds[0], 'rule' => $rule->describe(), 'score' => 1.0];
            } else {
                $result->conflicts[] = ['localIds' => $localIds, 'remoteIds' => $remoteIds, 'rule' => $rule->describe()];
            }

            $locals = array_diff_key($locals, array_flip($localIds));
            $remotes = array_diff_key($remotes, array_flip($remoteIds));
        }
    }

    /**
     * Pairs by score, best first. A pair above the auto-link threshold is a
     * match unless another pair of either item ties with it; above the
     * candidate threshold it is proposed; below, ignored.
     *
     * @param array<string, LocalItem> $locals remaining, reduced in place
     * @param array<string, RemoteItem> $remotes remaining, reduced in place
     */
    private function matchScored(
        SyncDefinition $definition,
        MatchRuleInterface $rule,
        array &$locals,
        array &$remotes,
        MatchResult $result,
    ): void {
        $pairs = [];
        foreach ($locals as $localId => $local) {
            foreach ($remotes as $remoteId => $remote) {
                $score = $rule->score($local, $remote);

                if ($score >= $definition->thresholds->candidate) {
                    $pairs[] = ['localId' => (string) $localId, 'remoteId' => (string) $remoteId, 'score' => $score];
                }
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        foreach ($pairs as $pair) {
            if (! isset($locals[$pair['localId']], $remotes[$pair['remoteId']])) {
                continue;
            }

            $rivals = array_filter($pairs, static fn (array $other): bool => $other !== $pair
                && $other['score'] === $pair['score']
                && isset($locals[$other['localId']], $remotes[$other['remoteId']])
                && ($other['localId'] === $pair['localId'] || $other['remoteId'] === $pair['remoteId']));

            if ([] !== $rivals) {
                $localIds = array_values(array_unique([$pair['localId'], ...array_column($rivals, 'localId')]));
                $remoteIds = array_values(array_unique([$pair['remoteId'], ...array_column($rivals, 'remoteId')]));
                $result->conflicts[] = ['localIds' => $localIds, 'remoteIds' => $remoteIds, 'rule' => $rule->describe()];
            } elseif ($pair['score'] >= $definition->thresholds->autoLink) {
                $result->matches[$pair['localId']] = ['remoteId' => $pair['remoteId'], 'rule' => $rule->describe(), 'score' => $pair['score']];
                $localIds = [$pair['localId']];
                $remoteIds = [$pair['remoteId']];
            } else {
                $result->candidates[] = ['localId' => $pair['localId'], 'remoteId' => $pair['remoteId'], 'rule' => $rule->describe(), 'score' => $pair['score']];
                $localIds = [$pair['localId']];
                $remoteIds = [$pair['remoteId']];
            }

            $locals = array_diff_key($locals, array_flip($localIds));
            $remotes = array_diff_key($remotes, array_flip($remoteIds));
        }
    }

    /**
     * @param array<string, LocalItem|RemoteItem> $items
     *
     * @return array<string, string[]> item ids by key, items without a key left out
     */
    private function group(array $items, callable $key): array
    {
        $groups = [];
        foreach ($items as $id => $item) {
            $value = $key($item);

            if (null !== $value) {
                $groups[$value][] = (string) $id;
            }
        }

        return $groups;
    }

    /**
     * @template T of LocalItem|RemoteItem
     *
     * @param T[] $items
     *
     * @return array<string, T>
     */
    private function byId(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        return $byId;
    }
}
