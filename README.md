# spora-plugin-usage

Usage metering for Spora: every provider call and every tool call is priced, billed against a credit pool, and stopped when it reaches a limit.

This plugin contributes the **Usage** app to the host's Apps dropdown. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-usage-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-usage
composer require spora-ai/spora-plugin-usage-frontend
```

Both packages are required: the PHP package ships the migration, the price book, the meter, the admin API and the admin-panel metadata (`UsageApp` → `VueAppInterface`), and the frontend package ships the Vue IIFE bundle that the host SPA lazy-loads from `/plugins/usage/main.js`.

Requires `spora-ai/spora-core` ≥ 0.33.0 — the floor the manifest declares. The plugin implements core's usage-metering seam (`Spora\Metering\UsageMeterInterface`), which the next core release carries. Core binds that seam to a null object so an install without a metering plugin behaves exactly as it did before the seam existed; this plugin's `ContainerBuildingEvent` binding replaces the null object with the meter that charges for the work.

## What it does

- **Meters every provider call and every tool call.** The core asks the meter before a request leaves and after it returns, so a run that is stopped by a limit is a run whose tokens are still counted.
- **Prices a model on its provider and its name**, and falls back to a row with an empty provider — so one price covers every provider that runs the same model. A tool is priced on its fully-qualified class name (the wire name changes between plugin releases, the class does not) and may be narrowed to a single operation.
- **Shows the work that is silently free.** A model or a tool with no price row costs nothing, and nothing else in the UI would say so. `GET /api/v1/usage/unpriced` lists what has been used and is not priced.
- **Charges the owner.** A group-shared agent charges the group pool; a user's own agent charges the user pool. An interactive run is charged to the individual as well as the owner; a run the platform started (a scheduled job) is charged to the owner alone — the trigger user of a platform-started run is whoever happened to fire the housekeeping, not an attribution.
- **Enforces limits, hard or soft.** A hard limit stops the run; a soft one is recorded and the call proceeds. A limit is a cap on one window — the last hour, day, week or month, a rolling window of N hours, or all of time — measured in credits, total tokens, output tokens, LLM calls or tool calls.
- **Spends against a credit pool.** A principal with a pool is debited on every metered event and blocked when the pool runs out. Credits are abstract: there is no currency, only a number the operator hands out.
- **Never reserves.** The cost of a call is unknown before it runs, so a run can overshoot a cap by the cost of one turn plus one tool call, and two tasks running at once can both pass the gate. A lock cannot span a provider call, so the overshoot is inherent rather than a bug.
- **Keeps the history on the principal, not on the task.** The ledger snapshots the owner, because a transferred agent's tasks are re-pointed and a ledger that followed them would move a group's March history onto its new owner.

## Not in this release

**The individual scope is charged but not yet configurable through the API.** An interactive run is charged to the triggering individual as well as to the owner, and the ledger records both. The admin API covers the **principal** scope only: there is no route that lists, limits or grants against a *user* scope, so a per-user allowance can only be created by writing the `usage_limits` / `usage_credits` rows directly.

The scope is inert until it is configured — a scope with no limit row and no credit pool never blocks a run — so nothing is stopped invisibly. It is the *configuration* that is missing, not the enforcement.

## Windows

Every window is **rolling**, never a calendar boundary: a day cap is the last 24 hours, so a run at 23:59 and one at 00:01 are treated alike and the boundary never moves under the operator's feet. The spend is measured in 15-minute buckets, so a cap can be reached up to 15 minutes early and up to 15 minutes late — the sum rounds up rather than down, which is the safe direction for a limit.

## API surface

Nine endpoints appear under `/api/v1/usage` after install. The two `me` routes answer for the caller's own visible principals; every other route is behind the admin gate.

- `GET /api/v1/usage/me` — the balances, the limits with what is left of each window, and the spend of the last 30 days of every principal the caller can act for.
- `GET /api/v1/usage/me/series?window=day|week|month` — the spend over a window, bucketed.
- `GET /api/v1/usage/principals` — every principal in the install, with the same picture.
- `GET /api/v1/usage/principals/{id}` — one principal, with its limits and its 30-day spend.
- `PUT /api/v1/usage/principals/{id}/limits` — replaces the **whole** set of limits for that principal; a limit that is absent from the payload is deleted.
- `PUT /api/v1/usage/principals/{id}/credits` — grants an amount (a positive number adds to the balance, a negative one takes from it) and records a grant row.
- `GET /api/v1/usage/prices` — the whole price book.
- `PUT /api/v1/usage/prices` — replaces the **whole** price book.
- `GET /api/v1/usage/unpriced` — the models and tools that have been used without a price row.

A malformed value is a **400** and a value the server reads but will not store — a window outside the vocabulary, a negative cap, a duplicate row — is a **422**. A missing principal is a **404**, and two rows that would collide are a **409**.

## Settings

One setting, declared bare and rewritten by the host to `SPORA_USAGE_RETENTION_DAYS`:

- `retention_days` (integer, default 90) — how long the ledger rows and the bucket rows are kept. A shorter retention keeps the tables small; a longer one keeps the history the reporting windows are drawn from.

## Translations

This plugin ships no translation catalogue. The host resolves a string through its own catalogue first and falls back to the English literal, so the operator copy renders in English until a `locales/<lang>.json` is added.

**Translated** — nothing yet.

**Never translated** — these strings are read by the model, so the operator sees exactly what the model sees: no tool description, no tool parameter description and no tool setting label is part of this plugin's surface.

When a catalogue is added, the key is the **English source string**, byte for byte — editing the English orphans the translation: the old entry matches nothing and the new English has no entry, so the string renders in English while the stale entry sits in the file looking perfectly fine. See the [plugin author guide → Translations](https://docs.spora-ai.com/develop/plugins/author-guide/translations).

## Reference

- [Concepts → Plugins](https://docs.spora-ai.com/reference/concepts/plugins-system) — the extension points a plugin uses, and the events it subscribes to
- [Plugin author guide](https://docs.spora-ai.com/develop/plugins/author-guide) — writing a plugin, shipping its frontend bundle, and its settings
- [REST API reference](https://docs.spora-ai.com/reference/api) — the host's endpoint list and the error registry

## License

MIT — see [LICENSE](LICENSE).
