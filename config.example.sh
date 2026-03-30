# OCI Instance Launcher Configuration
# ===========================================
# Copy this file to config.sh and customize.
# The launcher auto-loads config.sh when it exists.
# Keep real config.sh local; do not commit it.

# ===========================================
# TENANTS - Add your OCI tenants
# ===========================================
# Space-separated list of tenant directory names
TENANTS="maperryspeaks oci-phoenix-1-omeiyysa"

# Directory containing tenant config folders.
# Each tenant folder should have:
#   - config.config
#   - key.pem
CONFIG_DIR="/path/to/your/oci-tenants"

# ===========================================
# INSTANCE CONFIGS
# ===========================================
# Directory containing instance JSON configs (default: ./instances)
INSTANCES_DIR="./instances"

# The default launcher posture is:
# - Ubuntu 24 ARM64 image mappings per tenant
# - public IPv4 enabled
# - dual-stack enabled
# - 75GB boot disk default with 50GB floor

# ===========================================
# RETRY TIMING (all values in seconds)
# ===========================================
# Base sleep between retry cycles
BASE_SLEEP=180          # 3 minutes

# Minimum sleep (script speeds up over time)
MIN_SLEEP=30            # 30 seconds minimum

# How much faster each cycle (seconds reduced)
SPEED_UP_PER_CYCLE=15  # 3min -> 2:45 -> 2:30 -> 2:15...

# Random jitter range (adds unpredictability)
JITTER_MIN=-20          # -20 to +20 seconds
JITTER_MAX=20

# Delay between trying different tenants
TENANT_DELAY_MIN=2
TENANT_DELAY_MAX=8

# Delay between launching different instances
INSTANCE_DELAY_MIN=3
INSTANCE_DELAY_MAX=12

# Delay before each API call (avoid bursts)
LAUNCH_DELAY_MIN=0
LAUNCH_DELAY_MAX=5

# ===========================================
# RATE LIMIT HANDLING
# ===========================================
# Initial pause when rate limited
RATE_LIMIT_PAUSE=30

# Extra randomness on rate limit pause
RATE_LIMIT_JITTER_MIN=5
RATE_LIMIT_JITTER_MAX=20

# ===========================================
# LOGGING
# ===========================================
LOG_DIR="./logs"
WEB_LOG="./public/retry.log"

# ===========================================
# EXAMPLES OF ADDING MORE TENANTS
# ===========================================
# 1. Create a folder in CONFIG_DIR for your new tenant
# 2. Add config.config and key.pem to that folder
# 3. Add the folder name to TENANTS list above
#
# Example - adding "my-new-tenant":
# TENANTS="maperryspeaks oci-phoenix-1-omeiyysa my-new-tenant"
#
# Then add tenant mappings in launch-instances.sh:
# - get_public_subnet()
# - get_private_subnet()
# - get_image()
