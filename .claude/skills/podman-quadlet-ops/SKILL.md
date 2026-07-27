---
name: podman-quadlet-ops
description: "Menjalankan stack ini dengan Podman rootless: compose untuk dev dan Quadlet + systemd untuk produksi Ubuntu. Gunakan saat menulis atau memperbaiki compose.yaml, Containerfile, unit .container/.network/.volume, skrip provisioning atau deploy, dan saat mendiagnosis 'bind: permission denied' pada port 80/443, container mati setelah logout atau reboot, error permission pada storage/ dan bootstrap/cache, prompt short-name image yang menggantung, bentrok port di host, atau backup volume rootless yang gagal dipulihkan."
---

# Podman Rootless + Quadlet

**Podman-only.** Tidak ada `docker-compose.yml`, tidak ada perintah `docker` di skrip mana pun.
Dev: `podman compose`. Produksi (Ubuntu 24.04): unit Quadlet yang dijalankan systemd.

## Lima kegagalan yang selalu terjadi

| Gejala | Sebab | Perbaikan |
|---|---|---|
| `listen tcp :80: bind: permission denied` | rootless tidak boleh bind port < 1024 | `net.ipv4.ip_unprivileged_port_start=80` di `/etc/sysctl.d/` |
| Semua container mati setelah SSH ditutup / reboot | `linger` tidak aktif | `sudo loginctl enable-linger <user>` |
| `compose up` menggantung tanpa pesan | short name image memicu prompt registry | pakai FQIN: `docker.io/library/postgres:17` |
| Laravel gagal menulis `storage/` padahal `ls -l` normal | pemetaan UID rootless | `userns_mode: keep-id` pada service yang bind-mount source |
| Backup volume tidak bisa dipulihkan | volume rootless ber-UID termap, disalin mentah | dump logis lewat `podman exec`, bukan salin direktori |

## Prasyarat mesin (dev WSL2 maupun VPS)

```bash
podman --version                       # WAJIB ≥ 4.4, kalau tidak Quadlet tidak ada
echo 'net.ipv4.ip_unprivileged_port_start=80' | sudo tee /etc/sysctl.d/99-podman.conf
sudo sysctl --system
grep <user> /etc/subuid /etc/subgid    # kosong → usermod --add-subuids/--add-subgids
sudo loginctl enable-linger <user>
```

Ubuntu memakai AppArmor, bukan SELinux → label volume `:Z`/`:z` **tidak** diperlukan.
(Pindah ke RHEL/Rocky/Fedora → `:Z` jadi wajib.)

## Dev

```bash
podman compose up -d
podman compose exec app php artisan migrate --seed
podman compose exec app php artisan octane:reload
```

Alamat host dari dalam container: `host.containers.internal` (bukan `host.docker.internal`).

Windows: taruh repo di dalam **WSL2**, bukan di drive `D:`. `podman machine` hanya mem-mount
`%USERPROFILE%`, sehingga bind mount dari drive lain gagal.

## Produksi — Quadlet

Rootless → `~/.config/containers/systemd/`. Contoh worker antrean:

```systemd
[Unit]
Description=App queue worker
After=app-redis.service

[Container]
Image=ghcr.io/org/app:latest
Exec=php artisan horizon
EnvironmentFile=/etc/app/app.env
Network=app.network
AutoUpdate=registry

[Service]
Restart=always
TimeoutStopSec=3600     # job panjang tidak terpotong saat deploy

[Install]
WantedBy=default.target
```

```bash
systemctl --user daemon-reload
systemctl --user start app-horizon
journalctl --user -u app-horizon -f
```

`TimeoutStopSec` adalah pengganti `stop_grace_period` milik compose. Tanpa itu, deploy memotong
job yang sedang berjalan di tengah jalan.

## Deploy & rollback

```bash
podman auto-update --dry-run
podman auto-update
podman exec app-app php artisan migrate --force
podman exec app-app php artisan reload

# rollback
podman tag ghcr.io/org/app:<sha-lama> ghcr.io/org/app:latest
systemctl --user restart app-app app-horizon app-reverb
```

Jangan aktifkan `podman-auto-update.timer` untuk aplikasi yang punya migrasi DB — deploy tidak
boleh berjalan sendiri tanpa diawasi.

## Backup

```bash
podman exec app-pgsql pg_dump -U app app | gzip > /backup/db-$(date +%F).sql.gz
```

Jangan pernah menyalin `~/.local/share/containers/storage/volumes/` secara mentah.

## Uji yang membuktikan produksi benar

**Reboot VPS-nya.** Kalau seluruh service tidak naik sendiri, `linger` atau `[Install]` salah —
dan itu satu-satunya cara mengetahuinya sebelum listrik data center yang mengujinya.
