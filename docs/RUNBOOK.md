# Operations Runbook

Production deployment, monitoring, and disaster recovery procedures.

## Table of Contents

1. [Before You Begin](#before-you-begin)
2. [Deployment](#deployment)
3. [Rollback](#rollback)
4. [Backup & Restore](#backup--restore)
5. [Monitoring & Logging](#monitoring--logging)
6. [Troubleshooting](#troubleshooting)

---

## Before You Begin

### Prerequisites

- VPS provisioned with `bin/provision-vps` (see `deploy/quadlet/README.md`)
- Quadlet units installed and enabled
- Production image built and pushed to registry
- SSH access to VPS as application user
- Backup location configured (default: `./backups/`)

### Environments

| Env | APP_ENV | Logging | Telescope | Debug |
|-----|---------|---------|-----------|-------|
| local | local | stderr (JSON) | ✓ | true |
| staging | staging | stderr (JSON) | ✗ | false |
| production | production | stderr (JSON) | ✗ | false |

Adjust `APP_ENV` in `.container` units accordingly.

---

## Deployment

### Before Deploying

1. **Build and test image locally**
   ```bash
   podman build -f container/Containerfile.prod -t myapp:v1.2.3 .
   podman run --rm myapp:v1.2.3 php artisan --version
   ```

2. **Tag and push to registry**
   ```bash
   podman tag myapp:v1.2.3 docker.io/myorg/myapp:v1.2.3
   podman push docker.io/myorg/myapp:v1.2.3
   ```

3. **Take backup**
   ```bash
   ./bin/backup
   ```

### Deploy

```bash
./bin/deploy v1.2.3
```

**What it does:**
1. Pulls new image from registry
2. Stops application services (keeps infrastructure running)
3. Runs database migrations: `php artisan migrate --force`
4. Restarts all application services
5. Waits 30 seconds for application to become healthy
6. Exits with status 0 on success, 1 on failure

**Verify deployment:**
```bash
# Check status
systemctl --user status app.service
systemctl --user status horizon.service

# Check health endpoint
curl http://localhost/up

# Tail logs
journalctl --user -u app.service -f
```

### Common Deployment Tasks

#### Run artisan command post-deploy
```bash
podman exec app-app php artisan config:cache
podman exec app-app php artisan route:cache
podman exec app-app php artisan event:cache
```

#### Manually trigger migration
```bash
podman exec app-app php artisan migrate --force
```

#### Clear caches
```bash
podman exec app-app php artisan cache:clear
podman exec app-app php artisan view:clear
```

#### Restart specific service
```bash
systemctl --user restart app.service
systemctl --user restart horizon.service
```

---

## Rollback

### Automatic Rollback (Recommended)

```bash
./bin/rollback
```

**What it does:**
1. Finds previous release tag from git (or uses `latest`)
2. Confirms with user
3. Pulls previous image
4. Stops application services
5. Updates Quadlet units with previous image reference
6. Restarts services
7. Verifies health

If rollback is successful, the system is back on the previous version.

### Manual Rollback

If `bin/rollback` fails:

1. **Stop services**
   ```bash
   systemctl --user stop app.service horizon.service reverb.service scheduler.service
   ```

2. **Update Quadlet units**
   ```bash
   # Edit ~/.config/containers/systemd/app.container, horizon.container, etc.
   # Change Image= line to:
   # Image=docker.io/myorg/myapp:v1.2.2
   ```

3. **Reload and restart**
   ```bash
   systemctl --user daemon-reload
   systemctl --user start app.service horizon.service reverb.service scheduler.service
   ```

4. **Verify**
   ```bash
   curl http://localhost/up
   journalctl --user -u app.service -f
   ```

### When Rollback Is Not Enough

If previous version also has issues, restore from database backup:

```bash
# Check available backups
ls -lh backups/

# Restore from a known-good backup
./bin/restore backups/db_backup_20260726_120000.sql.gz

# Restart application
systemctl --user restart app.service horizon.service
```

---

## Backup & Restore

### Backup Strategy

- **Frequency:** Daily (schedule via cron)
- **Retention:** Last 30 backups kept automatically
- **Storage:** Logical SQL dumps, not volume copies
- **Why logical?** Rootless Podman uses UID mapping; raw volume copies may fail to restore

### Manual Backup

```bash
./bin/backup [BACKUP_DIR]
# Default: ./backups/
# Creates: backups/db_backup_YYYYMMDD_HHMMSS.sql.gz
```

**Verify backup:**
```bash
gunzip -t backups/db_backup_*.sql.gz
gunzip -dc backups/db_backup_*.sql.gz | head -20
```

### Automated Backup (Cron)

Add to application user's crontab:

```bash
crontab -e
# Add:
0 2 * * * /path/to/app/bin/backup /var/backups/myapp
```

Or use systemd timer:

```ini
# ~/.config/systemd/user/backup.timer
[Unit]
Description=Daily backup of application database

[Timer]
OnCalendar=daily
OnCalendar=*-*-* 02:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

```ini
# ~/.config/systemd/user/backup.service
[Unit]
Description=Backup application database
After=pgsql.service

[Service]
Type=oneshot
ExecStart=/home/app/myapp/bin/backup /var/backups/myapp
```

### Restore

```bash
./bin/restore backups/db_backup_20260726_120000.sql.gz
```

**What it does:**
1. Verifies backup integrity
2. Drops existing database
3. Creates empty database
4. Restores from backup via `psql`
5. Verifies restore by counting tables

**After restore:**
```bash
# Application uses restored data automatically
curl http://localhost/up

# Verify data
journalctl --user -u app.service -f
```

### Disaster Recovery

If PostgreSQL container is corrupted:

1. **Backup existing volume (just in case)**
   ```bash
   mv ~/.local/share/containers/storage/app-pgsql \
      ~/.local/share/containers/storage/app-pgsql.backup
   ```

2. **Restart PostgreSQL to recreate**
   ```bash
   systemctl --user restart pgsql.service
   ```

3. **Restore from backup**
   ```bash
   ./bin/restore backups/db_backup_YYYYMMDD_HHMMSS.sql.gz
   ```

4. **Verify application**
   ```bash
   curl http://localhost/up
   ```

---

## Monitoring & Logging

### Check Service Status

```bash
# All services
systemctl --user list-units --type=service --all

# Specific service
systemctl --user status app.service

# Service logs (last 50 lines)
journalctl --user -u app.service -n 50

# Real-time logs
journalctl --user -u app.service -f
```

### Health Checks

```bash
# Application health
curl http://localhost/up
# Returns: { "status": "up", "checks": { "database": "ok", "redis": "ok" } }

# PostgreSQL
podman exec app-pgsql pg_isready -U app

# Redis
podman exec app-redis redis-cli ping
# Returns: PONG

# MinIO
podman ps --filter "name=app-minio"
```

### Monitor Resource Usage

```bash
# CPU and memory by container
podman stats

# Disk usage
df -h ~/.local/share/containers/storage/

# Database size
podman exec app-pgsql psql -U app -d app -c "SELECT pg_size_pretty(pg_database_size('app'));"
```

### Log Locations

| Component | Location | Format |
|-----------|----------|--------|
| app | journalctl --user -u app.service | JSON (stderr) |
| horizon | journalctl --user -u horizon.service | JSON (stderr) |
| reverb | journalctl --user -u reverb.service | JSON (stderr) |
| scheduler | journalctl --user -u scheduler.service | JSON (stderr) |
| pgsql | journalctl --user -u pgsql.service | journald |

Search logs:
```bash
# By time range
journalctl --user -u app.service --since "2 hours ago"

# By severity
journalctl --user -u app.service -p err

# Specific string
journalctl --user -u app.service | grep "ERROR"
```

---

## Troubleshooting

### Application won't start

**Symptom:** `systemctl --user status app.service` shows failed

**Debug:**
```bash
# Check logs
journalctl --user -u app.service -n 100

# Check database connectivity
podman exec app-app php artisan tinker
>>> DB::connection()->getPdo()
# Should connect without error

# Check environment variables
podman inspect app-app | grep -A 20 "Env"
```

**Common causes:**
- Database migrations pending → `./bin/deploy` again to migrate
- APP_KEY missing or wrong → set in Quadlet units
- Redis not running → `systemctl --user start redis.service`

### Migrations stuck or failing

```bash
# Manually run migrations with output
podman exec app-app php artisan migrate --force --verbose

# Rollback last batch (if safe)
podman exec app-app php artisan migrate:rollback --batch=1

# Check migration status
podman exec app-app php artisan migrate:status
```

### Database too large

```bash
# Check size
podman exec app-pgsql psql -U app -d app -c "SELECT pg_size_pretty(pg_database_size('app'));"

# Vacuum (reclaim space)
podman exec app-pgsql vacuumdb -U app -d app

# Check table sizes
podman exec app-pgsql psql -U app -d app -c "
  SELECT schemaname, tablename, pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename))
  FROM pg_tables
  ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;
"
```

### Port conflicts

```bash
# Check which process is using port 80
ss -tulpn | grep :80
lsof -i :80

# Free the port
sudo systemctl stop nginx          # or apache2, or whatever
```

### Out of disk space

```bash
# Check usage
df -h

# Clean old backups manually
rm backups/db_backup_2026070{1..20}_*.sql.gz

# Clean Podman storage
podman system prune -a
```

### Container can't connect to other containers

```bash
# Check network
podman network ls
podman network inspect app

# Test connectivity from app container
podman exec app-app curl -v http://app-pgsql:5432

# If failed, recreate network
podman network rm app
systemctl --user daemon-reload
systemctl --user restart app.service
```

### Horizon not processing jobs

```bash
# Check Horizon status
journalctl --user -u horizon.service -f

# Check queue
podman exec app-app php artisan queue:failed

# Retry failed jobs
podman exec app-app php artisan queue:retry all

# Restart Horizon
systemctl --user restart horizon.service
```

### Memory leak in app or workers

```bash
# Check memory usage
podman stats --no-stream

# Restart service (limited to 500 requests per worker)
systemctl --user restart app.service

# If persistent, check for unbounded collections
grep -r "static " app/
```

---

## Checklists

### Pre-Deployment Checklist

- [ ] Feature branch merged to main
- [ ] Tests passing: `composer verify`
- [ ] Image built locally: `podman build -f container/Containerfile.prod`
- [ ] Image pushed to registry
- [ ] Backup taken: `./bin/backup`
- [ ] Approval from team lead
- [ ] Maintenance window planned (if needed)

### Post-Deployment Checklist

- [ ] Health check passes: `curl http://localhost/up`
- [ ] Logs show no errors: `journalctl --user -u app.service -n 20`
- [ ] All services running: `systemctl --user status app.service horizon.service`
- [ ] Database accessible: `podman exec app-app php artisan tinker`
- [ ] Feature works as expected (manual test)
- [ ] Monitoring alerts quiet or expected

### Incident Response Checklist

1. [ ] Assess impact and severity
2. [ ] If critical: `./bin/rollback` immediately
3. [ ] Check `journalctl` for errors
4. [ ] Check database health and backups
5. [ ] If data corruption suspected: restore from known-good backup
6. [ ] Notify stakeholders
7. [ ] Post-incident review

---

## Contact & Escalation

- **On-call engineer:** See Pagerduty / Slack channel
- **Database issues:** DBA team at #database-support
- **Infrastructure:** Ops team at #infrastructure
- **Security incidents:** #security or security@example.com

---

## References

- [Deploy/Quadlet setup](../deploy/quadlet/README.md)
- [OCTANE-RULES.md](./OCTANE-RULES.md) — worker state safety
- [GitHub](https://github.com/myorg/myapp) — source code and CI
