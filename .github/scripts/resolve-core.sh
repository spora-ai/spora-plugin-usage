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
# it. The test is on the installed source rather than on a version string,
# so the fallback disappears by itself once the seam is merged: the next run
# resolves `main` and finds it there.
set -euo pipefail

cd "$(dirname "$0")/../.."

SEAM_BRANCH='dev-feat/usage-metering-seam'

composer update spora-ai/spora-core --with-all-dependencies --no-interaction --prefer-dist

if [ -d vendor/spora-ai/spora-core/app/Metering ]; then
    exit 0
fi

echo "::notice::core's main does not carry the metering seam yet — resolving ${SEAM_BRANCH}"

composer require --no-update --no-interaction "spora-ai/spora-core:${SEAM_BRANCH}"
composer update spora-ai/spora-core --with-all-dependencies --no-interaction --prefer-dist
