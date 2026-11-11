<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use InvalidArgumentException;

/**
 * The key the buckets, the limits and the credit balances are stored under.
 *
 * The owner of a metered event is either a principal — a user or a group —
 * or the individual who triggered an interactive run. Both are addressed the
 * same way, so the scope carries the kind of owner as well as its id.
 */
final readonly class OwnerScope
{
    public const TYPE_PRINCIPAL = 'principal';
    public const TYPE_USER = 'user';

    public function __construct(
        public string $type,
        public int $id,
    ) {
        if ($type !== self::TYPE_PRINCIPAL && $type !== self::TYPE_USER) {
            throw new InvalidArgumentException(
                "An owner scope is a 'principal' or a 'user', got '{$type}'.",
            );
        }
    }

    public static function principal(int $id): self
    {
        return new self(self::TYPE_PRINCIPAL, $id);
    }

    public static function user(int $id): self
    {
        return new self(self::TYPE_USER, $id);
    }

    public function isPrincipal(): bool
    {
        return $this->type === self::TYPE_PRINCIPAL;
    }

    public function isUser(): bool
    {
        return $this->type === self::TYPE_USER;
    }
}
