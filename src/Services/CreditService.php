<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The credit pool an owner spends from.
 *
 * The metered path debits inside the ledger's transaction, so that the
 * balance and the record of the spend move together; {@see self::debit()} is
 * the same write for a caller that is not recording usage.
 */
final class CreditService
{
    /**
     * The owner's remaining credits, or null when the owner has no pool.
     *
     * A missing pool row means "no budget", not "a budget of zero": such an
     * owner is governed by its window limits alone.
     */
    public function balance(OwnerScope $owner): ?float
    {
        $row = Capsule::table('usage_credits')
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->first(['balance']);

        if ($row === null) {
            return null;
        }

        return is_numeric($row->balance) ? (float) $row->balance : 0.0;
    }

    /**
     * Take the credits off the owner's pool.
     *
     * Only a pool that already exists is debited. A pool row is a budget
     * the operator granted, and minting one on the first charge would turn
     * an owner who was never given a budget into one with a negative
     * balance — and therefore a blocked owner.
     *
     * The row is locked for the read, because the balance is read and then
     * written: two debits that read the same balance would otherwise both
     * write their own difference and one of them would be lost.
     */
    public function debit(OwnerScope $owner, float $credits): void
    {
        Capsule::connection()->transaction(function () use ($owner, $credits): void {
            $row = Capsule::table('usage_credits')
                ->where('owner_type', $owner->type)
                ->where('owner_id', $owner->id)
                ->lockForUpdate()
                ->first(['balance']);

            if ($row === null) {
                return;
            }

            $balance = is_numeric($row->balance) ? (float) $row->balance : 0.0;

            Capsule::table('usage_credits')
                ->where('owner_type', $owner->type)
                ->where('owner_id', $owner->id)
                ->update(['balance' => round($balance - $credits, 8)]);
        });
    }
}
