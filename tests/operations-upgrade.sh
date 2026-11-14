#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

office_test_root="$(mktemp -d)"
trap 'rm -rf "$office_test_root"' EXIT
git archive cfbf20e8f5f85001eea0be9a5dd3d53e5111e67a | tar -x -C "$office_test_root"
export OFFICE_TEST_ROOT="$office_test_root"
php tests/operations-upgrade.php
