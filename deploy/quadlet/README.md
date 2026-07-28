# Quadlet Units for Production Deployment

Quadlet files (`.container`, `.network`) for systemd-based container orchestration on VPS.

## Units

| Unit | Purpose | Port | Notes |
|------|---------|------|-------|
| `app.network` | Shared bridge network for all services | — | Connects containers to communicate internally |
| `pgsql.container` | PostgreSQL 17 database | 5432 | Data persisted to `~/.local/share/containers/storage/app-pgsql` |
| `redis.container` | Redis cache + session + queue | 6379 | Ephemeral (no persistence) |
| `minio.container` | S3-compatible object storage | 9000, 9001 | API on 9000, console on 9001 |
| `app.container` | Laravel Octane application server | 80 | Main HTTP endpoint; health check via `/up` |
| `horizon.container` | Queue worker (Horizon) | — | Processes jobs from Redis queue; **3600s graceful shutdown** |
| `reverb.container` | WebSocket server (Reverb) | 8080 | Proxied to 443 externally via Caddy/nginx |
| `scheduler.container` | Task scheduler (cron replacement) | — | Runs `php artisan schedule:work` |

## Setup

### 1. Provision VPS

```bash
sudo ./bin/provision-vps [USERNAME]
```

Checks and configures:
- Podman version >= 4.4
- Rootless user with subuid/subgid
- loginctl linger (services survive logout)
- ip_unprivileged_port_start=80 (bind privileged ports)

**Note:** User must exist or will be created.

### 2. Build and Push Image

```bash
# Build production image locally
podman build -f container/Containerfile.prod -t myapp:latest .

# Tag and push to registry
podman tag myapp:latest docker.io/myorg/myapp:latest
podman push docker.io/myorg/myapp:latest
```

Then update all `.container` files to reference your registry image:
- Change `Image=docker.io/library/myapp:latest` to your actual image

### 3. Set APP_KEY

Update `app.container`, `horizon.container`, `reverb.container`, `scheduler.container` with generated key:

```bash
APP_KEY=$(php artisan key:generate --show)
# Add to each unit:
# Environment=APP_KEY=base64:xxx
```

Or pass at runtime (Quadlet supports environment file):
```ini
[Container]
EnvironmentFiles=%h/.env-app
```

### 4. Install Units

Copy units to user's systemd config:
```bash
# As root
cp deploy/quadlet/*.network deploy/quadlet/*.container /home/$USERNAME/.config/containers/systemd/
chown $USERNAME:$USERNAME /home/$USERNAME/.config/containers/systemd/*
```

Or run provisioning script which does this automatically.

### 5. Load and Start

```bash
# As the application user
systemctl --user daemon-reload
systemctl --user start pgsql.service redis.service minio.service
systemctl --user start app.service horizon.service reverb.service scheduler.service

# Enable autostart on reboot
systemctl --user enable app.service horizon.service reverb.service scheduler.service
```

### 6. Verify

```bash
# Check status
systemctl --user status app.service
systemctl --user list-units --type=service --all

# Tail logs
journalctl --user -u app.service -f

# Hit health endpoint (after app is ready)
curl http://localhost/up
```

## Key Design Decisions

### Horizon: Long Graceful Shutdown

```ini
TimeoutStopSec=3600
```

Horizon (queue worker) can run long-running jobs. During deploy, systemd sends SIGTERM.
Horizon needs up to 1 hour to finish jobs gracefully before SIGKILL.

Without this, long jobs are cut short mid-execution.

### Storage Volumes

Each unit declares volumes in `~/.local/share/containers/storage/`:
- `app-pgsql`: Database files (persisted)
- `app-redis`: (ephemeral, no volume needed)
- `app-minio`: Object storage data
- `app-storage`: Laravel `storage/` directory
- `app-bootstrap`: Laravel `bootstrap/cache/`

Why not system-wide `/var/lib/containers`?
- Rootless Podman can't access `/var` directly
- User-level storage in `~/.local/share` is mapped via subuid/subgid
- Survives user session logout (with linger enabled)

### Health Checks

`app.container` includes health check calling `/up` endpoint:
```ini
HealthCmd=/usr/local/bin/php -r "exit((curl_exec(curl_init('http://localhost/up')) === false) ? 1 : 0);"
HealthInterval=30s
```

Systemd uses this to restart container if it stops responding. Quadlet merges this into
the generated systemd service.

## Debugging

### Container won't start
```bash
systemctl --user status app.service
journalctl --user -u app.service -n 50

# Run manually to see output
podman run -it --rm \
  --network app \
  -e DB_HOST=app-pgsql \
  docker.io/myorg/myapp:latest \
  php artisan tinker
```

### Port already in use
```bash
ss -tulpn | grep :80
lsof -i :80

# Fix: free the port or adjust PublishPort in unit
```

### Logs
```bash
# All app logs
journalctl --user -u app.service -f

# Specific lines
journalctl --user -u app.service --since "10 minutes ago" | grep ERROR
```

## References

- [Quadlet Manual](https://docs.podman.io/en/latest/_static/api/libpod/docs/Podman.md#quadlet) — official Quadlet docs
- [Systemd User Services](https://wiki.archlinux.org/title/Systemd/User) — how user-level systemd works
- [Rootless Podman](https://github.com/containers/podman/blob/main/docs/tutorials/rootless_tutorial.md) — comprehensive guide
