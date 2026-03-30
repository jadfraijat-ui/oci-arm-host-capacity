# OCI ARM Host Capacity Retry

Automated Oracle Cloud Infrastructure instance launcher with smart retry logic for ARM instances.

## Features

- **Directory-based configuration** - Drop JSON files into `instances/` to add instances
- **Multi-tenancy support** - Configure multiple OCI tenancies
- **Smart retry** - Adaptive sleep that speeds up over time (3min → 30s minimum)
- **Rate limiting** - Detects and handles rate limits with backoff
- **Random jitter** - Avoids patterns to maximize success chances
- **Live dashboard** - Watch progress in real-time via `live.html`

## Quick Start

```bash
# Edit instance configs
vim instances/*.json

# Run the launcher
./launch-instances.sh

# View live dashboard
open http://localhost:8080/live.html
```

## Instance Configuration

Create JSON files in `instances/` directory:

```json
{
  "name": "my-server",
  "hostname": "my-server",
  "shape": "VM.Standard.A1.Flex",
  "ocpus": 3,
  "memory": 18,
  "boot_size": 75,
  "network": {
    "dual_stack": true,
    "assign_public_ip": true
  }
}
```

Defaults are intentionally biased toward:
- `Ubuntu 24 ARM64` image mappings per tenant
- `VM.Standard.A1.Flex`
- `public dual-stack` when you do not override the network block
- `75GB` boot disk, with a floor of `50GB`

## Options

- `--instances-dir <dir>` - Custom instance configs directory
- `--tenants <list>` - Space-separated tenant list

## Architecture

- `launch-instances.sh` - Main retry orchestration
- `oci-arm-host-capacity/` - Forked OCI API client (with PHP 8.5 fixes)
- `instances/` - Instance configuration files
- `live.html` - Live monitoring dashboard

## License

MIT
