<?php

namespace Wexample\SymfonyDataSync\Class;

/**
 * Scores a fuzzy match must reach: above autoLink the pair is linked, above
 * candidate it is proposed to a human, below it counts as unmatched.
 */
final readonly class Thresholds
{
    public function __construct(
        public float $autoLink = 0.95,
        public float $candidate = 0.7,
    ) {
    }
}
