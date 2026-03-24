#!/bin/bash
set -uo pipefail

TENANTS="maperryspeaks oci-phoenix-1-omeiyysa"

get_subnet() {
    case "$1" in
        "maperryspeaks") echo "ocid1.subnet.oc1.us-chicago-1.aaaaaaaaavqoxq7tkzantrsx5mfry5l65xmkb2nsw3j6bgl4hmdpcyzwcsmq" ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.subnet.oc1.phx.aaaaaaaae36l65mttizeudw2rf3olrasquciix2inplxlw6i6tsic5vav52a" ;;
    esac
}

get_subnet_ipv6() {
    case "$1" in
        "maperryspeaks") echo "ocid1.subnet.oc1.us-chicago-1.aaaaaaaa4g5h62dquubq6q3o2x2xxhxh7m4mzx2d2xzzz7mzxz3o2x2xxhxh7" ;;
        "oci-phoenix-1-omeiyysa") echo "" ;;
    esac
}

get_image() {
    case "$1" in
        "maperryspeaks") echo "ocid1.image.oc1.us-chicago-1.aaaaaaaaoo4nzxuu6w5aty4ap6jjnfxebwwxhlfxj5gkzmtgtnsw24eksmia" ;;
        "oci-phoenix-1-omeiyysa") echo "ocid1.image.oc1.phx.aaaaaaaavfbkczxpy4zopkqswucpfx7tv7x5xyjvppsp4l5tjkwv5kctr3dq" ;;
    esac
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTANCES_DIR="${SCRIPT_DIR}/instances"
CONFIG_DIR="/Users/agent3/Projects/Sites/data/oci-tenants"
LOG_DIR="${SCRIPT_DIR}/logs"
LOCK_FILE="${SCRIPT_DIR}/.launch.lock"
CONFIGS_FILE="${SCRIPT_DIR}/.retry-configs.txt"
WEB_LOG="${SCRIPT_DIR}/public/retry.log"
CYCLE_COUNT_FILE="${SCRIPT_DIR}/.cycle_count"
TIMEOUT_MINUTES=3
BASE_SLEEP=180
MIN_SLEEP=30

mkdir -p "${SCRIPT_DIR}/public"

# Ensure clean state
rm -f "$CONFIGS_FILE"

while [[ $# -gt 0 ]]; do
    case $1 in
        --instances-dir)
            INSTANCES_DIR="$2"
            shift 2
            ;;
        --instances-dir=*)
            INSTANCES_DIR="${1#*=}"
            shift
            ;;
        --tenants)
            TENANTS="$2"
            shift 2
            ;;
        --tenants=*)
            TENANTS="${1#*=}"
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo "Options:"
            echo "  --instances-dir <dir>  Directory containing instance config files (default: ./instances)"
            echo "  --tenants <list>       Space-separated list of tenants (default: $TENANTS)"
            echo ""
            echo "Instance config files (JSON):"
            echo "  name           - Instance name/identifier"
            echo "  hostname       - Server hostname (default: same as name)"
            echo "  ocpus          - Number of OCPUs"
            echo "  memory         - Memory in GB"
            echo "  boot_size      - Boot volume size in GB"
            echo "  network:"
            echo "    dual_stack       - Enable IPv4+IPv6 (default: true)"
            echo "    assign_public_ip - Assign public IP (default: true)"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

mkdir -p "$LOG_DIR"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg" | tee -a "$LOG_DIR/launch.log" >> "$WEB_LOG"
}

acquire_lock() {
    if [ -f "$LOCK_FILE" ]; then
        LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
        if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
            log "Another instance running (PID $LOCK_PID), exiting"
            exit 0
        fi
        log "Stale lock found, removing"
        rm -f "$LOCK_FILE"
    fi
    echo $$ > "$LOCK_FILE"
}

release_lock() {
    rm -f "$LOCK_FILE"
}

get_ssh_key() {
    local tenant=$1
    local key_file="$CONFIG_DIR/$tenant/key.pem"
    chmod 600 "$key_file"
    ssh-keygen -f "$key_file" -y 2>/dev/null || echo ""
}

get_oci_env() {
    local tenant=$1
    local image_id=$2
    local subnet_id=$3
    local ocpus=$4
    local memory=$5
    local boot_size=$6
    local hostname=$7
    local assign_public_ip=$8
    local dual_stack=$9
    local config="$CONFIG_DIR/$tenant/config.config"
    
    local region=$(grep "^region=" "$config" | cut -d'=' -f2)
    local user=$(grep "^user=" "$config" | cut -d'=' -f2)
    local fingerprint=$(grep "^fingerprint=" "$config" | cut -d'=' -f2)
    local tenancy=$(grep "^tenancy=" "$config" | cut -d'=' -f2)
    local key_file=$(grep "^key_file=" "$config" | cut -d'=' -f2)
    local ssh_key=$(get_ssh_key "$tenant")
    
    local ipv6_enabled="false"
    local ipv6_subnet=""
    if [ "$dual_stack" = "true" ]; then
        ipv6_subnet=$(get_subnet_ipv6 "$tenant")
        if [ -n "$ipv6_subnet" ]; then
            ipv6_enabled="true"
        fi
    fi
    
    cat > "$SCRIPT_DIR/oci-arm-host-capacity/.env" << ENVEOF
OCI_REGION=$region
OCI_USER_ID=$user
OCI_TENANCY_ID=$tenancy
OCI_KEY_FINGERPRINT=$fingerprint
OCI_PRIVATE_KEY_FILENAME=$key_file
OCI_SUBNET_ID=$subnet_id
OCI_IMAGE_ID=$image_id
OCI_SHAPE=VM.Standard.A1.Flex
OCI_OCPUS=$ocpus
OCI_MEMORY_IN_GBS=$memory
OCI_BOOT_VOLUME_SIZE_IN_GBS=$boot_size
OCI_SSH_PUBLIC_KEY="$ssh_key"
OCI_HOSTNAME=$hostname
OCI_ASSIGN_PUBLIC_IP=$assign_public_ip
OCI_IPV6_ENABLED=$ipv6_enabled
OCI_IPV6_SUBNET_ID=$ipv6_subnet
ENVEOF
}

check_rate_limit() {
    local output=$1
    echo "$output" | grep -qiE '"(429|TooManyRequests|Rate.?limit)"' && return 0
    return 1
}

check_capacity() {
    local output=$1
    echo "$output" | grep -qi '"Out of host capacity"' && return 0
    return 1
}

check_success() {
    local output=$1
    # Check for errors first
    if echo "$output" | grep -q '"code"'; then
        if echo "$output" | grep -q '"display-name"'; then
            return 0
        fi
        return 1
    fi
    # Empty or invalid output is failure
    return 1
}

random_delay() {
    local min=${1:-1}
    local max=${2:-10}
    local delay=$((RANDOM % (max - min + 1) + min))
    log "Random delay: ${delay}s (avoiding pattern detection)"
    sleep "$delay"
}

backoff_rate_limit() {
    log "RATE LIMIT DETECTED - backing off for 30 seconds..."
    sleep 30
    # Add random jitter to avoid predictable patterns
    random_delay 5 20
}

launch_instance() {
    local tenant=$1
    local name=$2
    local ocpus=$3
    local memory=$4
    local boot_size=$5
    local hostname=$6
    local assign_public_ip=$7
    local dual_stack=$8
    
    if [ ! -f "$CONFIG_DIR/$tenant/config.config" ] || [ ! -f "$CONFIG_DIR/$tenant/key.pem" ]; then
        log "ERROR: Missing config or key for $tenant"
        return 2
    fi
    
    local image_id=$(get_image "$tenant")
    local subnet_id=$(get_subnet "$tenant")
    
    if [ -z "$subnet_id" ] || [ -z "$image_id" ]; then
        log "ERROR: Missing subnet or image for $tenant"
        return 2
    fi
    
    local net_info=""
    if [ "$assign_public_ip" = "true" ]; then
        net_info="${net_info}public_ip+"
    fi
    if [ "$dual_stack" = "true" ]; then
        net_info="${net_info}dual_stack"
    fi
    [ -n "$net_info" ] && net_info=" [$net_info]" || net_info=""
    
    log "LAUNCH: $tenant - $name (${ocpus}OCPU, ${memory}GB RAM, ${boot_size}GB disk)$net_info"
    
    # Random delay before launch to avoid patterns
    random_delay 0 5
    
    get_oci_env "$tenant" "$image_id" "$subnet_id" "$ocpus" "$memory" "$boot_size" "$hostname" "$assign_public_ip" "$dual_stack"
    
    local output
    output=$(php "$SCRIPT_DIR/oci-arm-host-capacity-fixed/index.php" 2>/dev/null)
    echo "$output" >> "$LOG_DIR/launch-$tenant.log"
    echo "$output"
    
    if check_rate_limit "$output"; then
        echo "RATELIMIT" > /tmp/oracle-retry-ratelimit
        return 3
    fi
    
    if check_success "$output"; then
        return 0
    else
        return 1
    fi
}

load_instances_clean() {
    local dir=$1
    local instances=()
    
    if [ ! -d "$dir" ]; then
        echo "ERROR: Instances directory not found: $dir" >&2
        exit 1
    fi
    
    for file in "$dir"/*.json; do
        [ -f "$file" ] || continue
        
        local name hostname ocpus memory boot_size dual_stack assign_public_ip
        name=$(jq -r '.name // empty' "$file" 2>/dev/null)
        hostname=$(jq -r '.hostname // .name // empty' "$file" 2>/dev/null)
        ocpus=$(jq -r '.ocpus // empty' "$file" 2>/dev/null)
        memory=$(jq -r '.memory // empty' "$file" 2>/dev/null)
        boot_size=$(jq -r '.boot_size // empty' "$file" 2>/dev/null)
        
        dual_stack=$(jq -rn "($(jq -r '.network.dual_stack' "$file")) | if . == true then \"true\" else \"false\" end" 2>/dev/null)
        assign_public_ip=$(jq -rn "($(jq -r '.network.assign_public_ip' "$file")) | if . == true then \"true\" else \"false\" end" 2>/dev/null)
        
        if [ -z "$name" ] || [ -z "$ocpus" ] || [ -z "$memory" ] || [ -z "$boot_size" ]; then
            continue
        fi
        
        [ -z "$hostname" ] && hostname="$name"
        
        instances+=("${name}:${ocpus}:${memory}:${boot_size}:${hostname}:${assign_public_ip}:${dual_stack}")
    done
    
    if [ ${#instances[@]} -eq 0 ]; then
        echo "ERROR: No valid instance configs found in $dir" >&2
        exit 1
    fi
    
    printf '%s\n' "${instances[@]}"
}

load_instances() {
    local dir=$1
    local instances=()
    
    if [ ! -d "$dir" ]; then
        log "ERROR: Instances directory not found: $dir"
        exit 1
    fi
    
    for file in "$dir"/*.json; do
        [ -f "$file" ] || continue
        
        local name hostname ocpus memory boot_size dual_stack assign_public_ip
        name=$(jq -r '.name // empty' "$file" 2>/dev/null)
        hostname=$(jq -r '.hostname // .name // empty' "$file" 2>/dev/null)
        ocpus=$(jq -r '.ocpus // empty' "$file" 2>/dev/null)
        memory=$(jq -r '.memory // empty' "$file" 2>/dev/null)
        boot_size=$(jq -r '.boot_size // empty' "$file" 2>/dev/null)
        
        dual_stack=$(jq -rn "($(jq -r '.network.dual_stack' "$file")) | if . == true then \"true\" else \"false\" end" 2>/dev/null)
        assign_public_ip=$(jq -rn "($(jq -r '.network.assign_public_ip' "$file")) | if . == true then \"true\" else \"false\" end" 2>/dev/null)
        
        if [ -z "$name" ] || [ -z "$ocpus" ] || [ -z "$memory" ] || [ -z "$boot_size" ]; then
            log "WARNING: Skipping $file - missing required fields"
            continue
        fi
        
        [ -z "$hostname" ] && hostname="$name"
        
        instances+=("${name}:${ocpus}:${memory}:${boot_size}:${hostname}:${assign_public_ip}:${dual_stack}")
        log "Loaded: $name (${ocpus}OCPU, ${memory}GB RAM, ${boot_size}GB disk, hostname=$hostname, dual_stack=$dual_stack, public_ip=$assign_public_ip)"
    done
    
    if [ ${#instances[@]} -eq 0 ]; then
        log "ERROR: No valid instance configs found in $dir"
        exit 1
    fi
    
    printf '%s\n' "${instances[@]}"
}

main() {
    acquire_lock
    trap release_lock EXIT
    
    log "=== Starting Oracle Instance Retry ==="
    log "Instances dir: $INSTANCES_DIR"
    log "Tenants: $TENANTS"
    
    # Load instances directly without log output
    load_instances_clean "$INSTANCES_DIR" > "$CONFIGS_FILE"
    
    # Log loaded instances
    while IFS=':' read -r name ocpus memory boot_size hostname assign_public_ip dual_stack; do
        [ -z "$name" ] && continue
        log "Loaded: $name (${ocpus}OCPU, ${memory}GB RAM, ${boot_size}GB disk, hostname=$hostname, dual_stack=$dual_stack, public_ip=$assign_public_ip)"
    done < "$CONFIGS_FILE"
    
    local success_count=0
    local fail_count=0
    local rate_limit_count=0
    local cycle_start=$(date +%s)
    
    while IFS=':' read -r name ocpus memory boot_size hostname assign_public_ip dual_stack; do
        [ -z "$name" ] && continue
        
        local launched=false
        for tenant in $TENANTS; do
            log "Trying $tenant for $name..."
            
            local result
            launch_instance "$tenant" "$name" "$ocpus" "$memory" "$boot_size" "$hostname" "$assign_public_ip" "$dual_stack"
            result=$?
            
            if [ $result -eq 0 ]; then
                log "SUCCESS: Launched $name on $tenant"
                launched=true
                success_count=$((success_count + 1))
                break
            elif [ $result -eq 3 ]; then
                # Rate limited - backoff and skip remaining tenants
                backoff_rate_limit
                rate_limit_count=$((rate_limit_count + 1))
                log "RATE LIMIT: Skipping remaining tenants for $name"
                break
            else
                log "FAILED: $tenant failed, trying next..."
                fail_count=$((fail_count + 1))
                # Small random delay between tenant attempts
                random_delay 2 8
            fi
        done
        
        if [ "$launched" = false ]; then
            log "WARNING: Could not launch $name on any tenant"
        fi
        
        # Random delay between instances
        if [ "$launched" = false ]; then
            random_delay 3 12
        fi
    done < "$CONFIGS_FILE"
    
    local cycle_end=$(date +%s)
    local cycle_duration=$((cycle_end - cycle_start))
    
    log "=== Cycle Complete: $success_count success, $fail_count failed, $rate_limit_count rate limited, took ${cycle_duration}s ==="
    
    release_lock
    
    # Adaptive sleep - speeds up as cycles increase
    local cycle_count
    cycle_count=$(cat "$CYCLE_COUNT_FILE" 2>/dev/null || echo 0)
    cycle_count=$((cycle_count + 1))
    echo "$cycle_count" > "$CYCLE_COUNT_FILE"
    
    # Start at 3min, decrease by 15s every cycle, min 30s
    local adaptive_sleep=$((BASE_SLEEP - (cycle_count * 15)))
    [ $adaptive_sleep -lt $MIN_SLEEP ] && adaptive_sleep=$MIN_SLEEP
    
    # Add random jitter to avoid patterns (-20 to +20 seconds)
    local jitter=$((RANDOM % 41 - 20))
    local total_sleep=$((adaptive_sleep + jitter))
    [ $total_sleep -lt $MIN_SLEEP ] && total_sleep=$MIN_SLEEP
    
    log "Cycle $cycle_count - Sleeping ${total_sleep}s (base: ${adaptive_sleep}s, jitter: ${jitter}s) before next attempt..."
    sleep "${total_sleep}"
    
    exec "$0" "$@"
}

main "$@"
