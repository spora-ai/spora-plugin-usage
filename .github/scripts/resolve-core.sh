#!/usr/bin/env bash
#
# Resolve spora-ai/spora-core for the suite.
#
# The plugin needs the metering seam (spora-ai/spora-core#323), and the
# seam is not on `main` yet. Resolving `main` gives a core without
# `app/Metering`, and every `Spora\Metering\*` class is then unknown to the
# analyser and to the test suite — the plugin's CI cannot pass against a
# core that does not have the interface the plugin implements.
#
# So the branch that carries the seam is resolved when `main` does not have
# it. The test is on the source rather than on a version string, so the
# fallback disappears by itself once the seam is merged: the next run
# resolves `main` and finds it there.
#
# The check is a shallow clone of the candidate, not the installed
# package. A clone is not rate-limited the way the API is — and the API
# is shared by every runner on the pool, so an unauthenticated composer
# can be refused a rate limit that has nothing to do with this branch.
set -euo pipefail

cd "$(dirname "$0")/../.."

SEAM_BRANCH='feat/usage-metering-seam'
CORE_REPO='https://github.com/spora-ai/spora-core.git'

ref=main
probe=$(mktemp -d)
trap 'rm -rf "$probe"' EXIT

if ! git clone --depth 1 --quiet --branch main "$CORE_REPO" "$probe/main" 2>/dev/null \
    || [ ! -d "$probe/main/app/Metering" ]; then
    ref="$SEAM_BRANCH"
fi

echo "Resolving spora-ai/spora-core at ${ref}."

composer require --no-update --no-interaction "spora-ai/spora-core:dev-${ref}"
composer update spora-ai/spora-core --with-all-dependencies --no-interaction --prefer-dist
