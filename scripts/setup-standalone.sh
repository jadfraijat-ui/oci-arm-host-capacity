#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$REPO_DIR"

mkdir -p "$REPO_DIR/logs" "$REPO_DIR/public" "$REPO_DIR/instances"

if [ ! -f "$REPO_DIR/config.sh" ]; then
    cp "$REPO_DIR/config.example.sh" "$REPO_DIR/config.sh"
    echo "Created $REPO_DIR/config.sh from config.example.sh"
else
    echo "Keeping existing $REPO_DIR/config.sh"
fi

chmod +x "$REPO_DIR/launch-instances.sh"

if command -v composer >/dev/null 2>&1; then
    (
        cd "$REPO_DIR/oci-arm-host-capacity-fixed"
        composer install --no-dev --prefer-dist --no-interaction
    )
else
    echo "Composer not found; skipping vendor install for oci-arm-host-capacity-fixed"
fi

cat <<EOF

Standalone repo setup is ready.

Next steps:
1. Edit $REPO_DIR/config.sh
2. Point CONFIG_DIR at your OCI tenant credential folders
3. Add or adjust instance JSON files in $REPO_DIR/instances
4. Run: $REPO_DIR/launch-instances.sh
5. When the standalone copy is ready, sync code into AI Control Panel with:
   $REPO_DIR/scripts/sync-to-ai-control-panel.sh

EOF
