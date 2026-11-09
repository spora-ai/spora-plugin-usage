<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http;

use DateTimeImmutable;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\WindowCalculator;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The caller's own usage view.
 *
 * Everything here answers for the caller's **visible principals** — their
 * own user-principal and the groups they belong to. A principal the caller
 * cannot act as is not in the answer at all: the endpoint is scoped by
 * {@see PrincipalResolver::visiblePrincipalIds()}, so there is no id in the
 * request that could widen it.
 */
final class UsageController
{
    use JsonControllerHelpers;

    /**
     * The windows the series endpoint reports on.
     */
    private const SERIES_WINDOWS = [
        WindowCalculator::WINDOW_DAY,
        WindowCalculator::WINDOW_WEEK,
        WindowCalculator::WINDOW_MONTH,
    ];

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalResolver $principals,
        private readonly UsageStore $store,
    ) {}

    /**
     * The balances, the limits and the spend of every principal the caller
     * can act for.
     */
    public function me(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $now = new DateTimeImmutable();
        $summaries = [];

        foreach ($this->principals->visiblePrincipalIds($userId) as $principalId) {
            $summary = $this->store->principalSummary($principalId, $now);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        return new JsonResponse(['data' => ['principals' => $summaries]]);
    }

    /**
     * The spend over a window, bucketed.
     *
     * The series is the caller's visible principals merged: the caller sees
     * one line of spend, not one per principal, because the panel draws one
     * chart.
     */
    public function series(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $window = $request->query->get('window', WindowCalculator::WINDOW_DAY);
        if (!in_array($window, self::SERIES_WINDOWS, true)) {
            return $this->error(
                'BAD_REQUEST',
                'window must be one of: day, week, month.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $now = new DateTimeImmutable();
        $points = [];

        foreach ($this->principals->visiblePrincipalIds($userId) as $principalId) {
            foreach ($this->store->series(OwnerScope::TYPE_PRINCIPAL, $principalId, $window, 1, $now) as $point) {
                $at = (string) $point['at'];
                $points[$at] ??= [
                    'at' => $at,
                    'credits' => 0.0,
                    'total_tokens' => 0,
                    'output_tokens' => 0,
                    'llm_calls' => 0,
                    'tool_calls' => 0,
                ];
                $points[$at]['credits'] += (float) $point['credits'];
                $points[$at]['total_tokens'] += (int) $point['total_tokens'];
                $points[$at]['output_tokens'] += (int) $point['output_tokens'];
                $points[$at]['llm_calls'] += (int) $point['llm_calls'];
                $points[$at]['tool_calls'] += (int) $point['tool_calls'];
            }
        }

        // Every point carries the same offset, so the key order is the time
        // order and the sort leaves the oldest point first.
        ksort($points);

        return new JsonResponse(['data' => [
            'window' => $window,
            'points' => array_values($points),
        ]]);
    }
}
