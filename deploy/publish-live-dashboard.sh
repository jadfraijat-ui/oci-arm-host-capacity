#!/usr/bin/env bash
set -euo pipefail

RETRY_ROOT="${RETRY_ROOT:-/opt/aietherpanel/oracle-retry}"
PANEL_PUBLIC_ROOT="${PANEL_PUBLIC_ROOT:-/opt/aietherpanel/controller/public}"
TARGET_LINK="${TARGET_LINK:-${PANEL_PUBLIC_ROOT}/oracle-retry}"

if [ ! -d "$RETRY_ROOT" ]; then
  echo "oracle-retry root not found: $RETRY_ROOT" >&2
  exit 1
fi

mkdir -p "$PANEL_PUBLIC_ROOT"

if [ -e "$TARGET_LINK" ] || [ -L "$TARGET_LINK" ]; then
  rm -rf "$TARGET_LINK"
fi

ln -s "$RETRY_ROOT" "$TARGET_LINK"
echo "Published oracle-retry dashboard: $TARGET_LINK -> $RETRY_ROOT"
