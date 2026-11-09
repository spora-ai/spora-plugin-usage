<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http;

use DateTimeImmutable;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Usage\Http\Exceptions\UsagePayloadException;
use Spora\Plugins\Usage\Models\UsageLimit;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\WindowCalculator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The operator's view of every principal, the limits and the price book.
 *
 * Every route here is behind `AdminMiddleware` — the admin tabs of the Usage
 * app hide themselves for a non-admin, but the API is the gate that decides.
 *
 * A malformed value is a 400 and a value the server reads but will not store
 * is a 422: a limit whose window is not a window, or a cap that is negative,
 * is a refusal rather than a broken request. {@see UsagePayloadParser} draws
 * that line; this class only decides what happens with the payload it
 * reads.
 */
final class UsageAdminController
{
    use JsonControllerHelpers;

    private const ERR_PRINCIPAL_NOT_FOUND = 'Principal not found.';

    public function __construct(
        private readonly UsageStore $store,
        private readonly UsagePriceStore $priceStore,
        private readonly UsagePayloadParser $parser,
    ) {}

    /**
     * Every principal in the install, with its balance, limits and spend.
     */
    public function index(): JsonResponse
    {
        $now = new DateTimeImmutable();
        $summaries = [];

        foreach ($this->store->principalIds() as $principalId) {
            $summary = $this->store->principalSummary($principalId, $now);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        return new JsonResponse(['data' => ['principals' => $summaries]]);
    }

    /**
     * One principal, with its limits and its spend.
     */
    public function show(int $id): JsonResponse
    {
        $now = new DateTimeImmutable();
        $summary = $this->store->principalSummary($id, $now);
        if ($summary === null) {
            return $this->notFound('NOT_FOUND', self::ERR_PRINCIPAL_NOT_FOUND);
        }

        return new JsonResponse(['data' => [
            'principal' => [
                'id' => $summary['id'],
                'type' => $summary['type'],
                'name' => $summary['name'],
            ],
            'balance' => $summary['balance'],
            'limits' => $summary['limits'],
            'spend' => [
                'credits_30d' => $summary['spend_30d'],
                // The token total is the same window's other counter: the
                // summary carries the credits, the store counts the tokens.
                'total_tokens_30d' => (int) $this->store->bucketSum(
                    OwnerScope::TYPE_PRINCIPAL,
                    $id,
                    WindowCalculator::WINDOW_MONTH,
                    1,
                    UsageLimit::UNIT_TOTAL_TOKENS,
                    $now,
                ),
            ],
        ]]);
    }

    /**
     * Replace the limits of one principal.
     *
     * The payload is the whole set: a limit that is absent from it is
     * deleted, so the operator's screen and the stored set cannot drift
     * apart one limit at a time.
     */
    public function replaceLimits(int $id, Request $request): JsonResponse
    {
        if ($this->store->principal($id) === null) {
            return $this->notFound('NOT_FOUND', self::ERR_PRINCIPAL_NOT_FOUND);
        }

        try {
            $limits = $this->parser->parseLimits($request);
        } catch (UsagePayloadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        }

        $this->store->replaceLimits(OwnerScope::TYPE_PRINCIPAL, $id, $limits);

        // The answer carries the stored rows with their figures, which the
        // summary already holds.
        $summary = $this->store->principalSummary($id, new DateTimeImmutable());

        return new JsonResponse(['data' => [
            'limits' => $summary['limits'] ?? [],
        ]]);
    }

    /**
     * Grant credits to one principal and record the grant.
     *
     * A positive amount adds to the balance, a negative one takes from it —
     * the correction an operator makes after a mistake goes through the same
     * door as the grant itself, so the balance always has a paper trail.
     *
     * The balance may end up negative: the correction is the operator's
     * decision, and a pool that is overdrawn is a pool that is blocked.
     */
    public function grantCredits(int $id, Request $request): JsonResponse
    {
        if ($this->store->principal($id) === null) {
            return $this->notFound('NOT_FOUND', self::ERR_PRINCIPAL_NOT_FOUND);
        }

        try {
            $grant = $this->parser->parseGrant($request);
        } catch (UsagePayloadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        }

        $balance = $this->store->grant(
            OwnerScope::TYPE_PRINCIPAL,
            $id,
            $grant['amount'],
            $grant['note'],
        );

        return new JsonResponse(['data' => ['balance' => $balance]]);
    }

    /**
     * The whole price book.
     */
    public function prices(): JsonResponse
    {
        return new JsonResponse(['data' => ['prices' => $this->priceStore->prices()]]);
    }

    /**
     * Replace the price book with the given rows.
     */
    public function replacePrices(Request $request): JsonResponse
    {
        try {
            $prices = $this->parser->parsePrices($request);
        } catch (UsagePayloadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        }

        $this->priceStore->replacePrices($prices);

        return new JsonResponse(['data' => ['prices' => $this->priceStore->prices()]]);
    }

    /**
     * The models and tools that were metered without a price row.
     */
    public function unpriced(): JsonResponse
    {
        return new JsonResponse(['data' => $this->priceStore->unpriced()]);
    }
}
