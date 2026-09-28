<?php

namespace Wexample\SymfonyDataSync\Enum;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * How a value is prepared before two are compared.
 */
enum MatchNormalizer: string
{
    case Trim = 'trim';
    case Lower = 'lower';

    /** Trimmed and lowercased: addresses differ only by case. */
    case Email = 'email';

    /** ASCII, lowercased, dashes: "Projet Été" and "projet-ete" meet. */
    case Slug = 'slug';

    public function apply(string $value): string
    {
        return match ($this) {
            self::Trim => trim($value),
            self::Lower => mb_strtolower($value),
            self::Email => mb_strtolower(trim($value)),
            self::Slug => (new AsciiSlugger())->slug($value)->lower()->toString(),
        };
    }
}
