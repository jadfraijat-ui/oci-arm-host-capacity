# OCI ARM Host Capacity Retry

Automated Oracle Cloud Infrastructure instance launcher with smart retry logic for ARM instances.
This repo is the source of truth for the retry tool. After the standalone copy is set up and verified,
you sync the runtime code into `ai-control-panel` instead of editing the embedded copy first.

Current release: `1.2.1`

## Features

- **Directory-based configuration** - Drop JSON files into `instances/` to add instances
- **Multi-tenancy support** - Configure multiple OCI tenancies
- **Smart retry** - Adaptive sleep that speeds up over time (3min → 30s minimum)
- **Rate limiting** - Detects and handles rate limits with backoff
- **Random jitter** - Avoids patterns to maximize success chances
- **Live dashboard** - Watch progress in real-time via `live.html`

## Quick Start

```bash
# One-time standalone setup
./scripts/setup-standalone.sh

# Edit config + instance files
vim config.sh
vim instances/*.json

# Run the launcher from the standalone repo
./launch-instances.sh

# View live dashboard
open http://localhost:8080/
```

## What belongs in this repo

- `launch-instances.sh` - main retry orchestration
- `live.html` - live monitoring dashboard
- `index.html` - stable root entry that opens the live dashboard
- `dashboard.html` - full log view
- `upload-tenant.php` - tenant profile/key upload endpoint used by the live panel
- `remove-instance.php` - archive/remove endpoint for queued instances
- `deploy/` - service and publish helpers for the live retry panel
- `config.example.sh` - template for local runtime config
- `INSTANCE_CONFIG.md` - instance manifest reference
- `instances/*.example` - safe example manifests
- `oci-arm-host-capacity-fixed/` - standalone OCI PHP helper
- `scripts/setup-standalone.sh` - first-time local bootstrap
- `scripts/sync-to-ai-control-panel.sh` - copy the clean runtime code back into AI Control Panel

Do not commit:
- `config.sh`
- OCI tenant private keys or tenant `config.config` secrets
- `.env` inside `oci-arm-host-capacity-fixed/`
- `vendor/`
- logs, locks, or runtime queue files

## Instance Configuration

Create JSON files in `instances/` directory:

```json
{
  "name": "free-arm-core",
  "hostname": "free-arm-core",
  "shape": "VM.Standard.A1.Flex",
  "ocpus": 4,
  "memory": 24,
  "boot_size": 200,
  "network": {
    "dual_stack": true,
    "assign_public_ip": true
  }
}
```

Defaults are intentionally biased toward:
- `Ubuntu 24 Minimal ARM64` image mappings per tenant
- `VM.Standard.A1.Flex`
- `public dual-stack` when you do not override the network block
- the full `4 OCPU / 24GB / 200GB` Always Free ARM box when the tenant is empty

## Standalone setup

1. Run `./scripts/setup-standalone.sh`
2. Edit `config.sh`
3. Point `CONFIG_DIR` at the OCI tenant folders that already contain `config.config` and `key.pem`
4. Create or edit `instances/*.json`
5. Run `./launch-instances.sh`

The launcher defaults are now biased toward:
- public IPv4 + IPv6
- Ubuntu 24 Minimal ARM64 tenant image mappings
- `VM.Standard.A1.Flex`
- `200GB` boot volume with a `50GB` floor

## Network target

The intended default network posture for launched hosts is:
- one public IPv4 assigned on launch
- IPv6 enabled on launch
- public dual-stack subnet placement by default

The OCI launcher already requests the public IPv4 + initial IPv6 at launch time. The remaining network goal
is to make the tenant bootstrap and post-launch tooling capable of driving a VNIC up to OCI's documented
IPv6 object ceiling of 32 per VNIC when that is useful for the workload.

This repo does **not** currently claim to create 32 IPv6 objects during instance launch. That remains tracked
as follow-up work, separate from the base dual-stack launch behavior.

## Sync into AI Control Panel

Once the standalone copy is working, sync the source-controlled runtime files into AI Control Panel:

```bash
./scripts/sync-to-ai-control-panel.sh
```

That sync now targets `/opt/aietherpanel/oracle-retry` by default so the live Ryzen runtime and this repo stay aligned.

That sync intentionally skips local-only files like `config.sh`, logs, OCI secrets, PHP helper `.env`,
`vendor/`, and the live `instances/*.json` manifests.

## Options

- `--instances-dir <dir>` - Custom instance configs directory
- `--tenants <list>` - Space-separated tenant list

## Architecture

- `launch-instances.sh` - Main retry orchestration
- `oci-arm-host-capacity-fixed/` - Forked OCI API client (with PHP 8.5 fixes)
- `instances/` - Instance configuration files
- `live.html` - Live monitoring dashboard

## License

MIT
