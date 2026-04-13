#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
TARGET_DIR="${1:-/opt/aietherpanel/oracle-retry}"

mkdir -p "$TARGET_DIR"

rsync -av \
    --delete \
    --exclude='.git/' \
    --exclude='config.sh' \
    --exclude='logs/' \
    --exclude='public/' \
    --exclude='.launch.lock' \
    --exclude='.cycle_count' \
    --exclude='.retry-configs.txt' \
    --exclude='oci-arm-host-capacity-fixed/.env' \
    --exclude='oci-arm-host-capacity-fixed/vendor/' \
    --exclude='instances/*.json' \
    "$REPO_DIR/" "$TARGET_DIR/"

mkdir -p "$TARGET_DIR/instances"

cat <<EOF

Synced source-of-truth code into:
  $TARGET_DIR

Not copied on purpose:
- local config.sh
- local logs/public runtime files
- OCI tenant secrets
- PHP helper .env and vendor
- live instance manifests in instances/*.json

Copy or create the target-side instance JSONs separately if that controller should run them.

EOF
