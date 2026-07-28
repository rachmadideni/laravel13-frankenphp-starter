# laravel13-frankenphp-starter

Boilerplate reusable untuk aplikasi web full-stack, dirancang untuk dibangun oleh agen otonom
lewat [ralph-tui](https://ralph-tui.com/).

**Stack**: Laravel 13 (PHP 8.3) di FrankenPHP/Octane worker mode · Reverb (realtime) ·
Horizon + Redis (queue) · PostgreSQL 17 · Sanctum mode cookie · spatie/laravel-permission ·
S3/MinIO · Vue 3 + Vuetify 3 + Pinia · Podman (compose untuk dev, Quadlet + systemd untuk produksi).

**Tanpa domain bisnis.** Hanya `User`, `Role`, `Permission`, dan satu resource contoh berlabel
`@demo` yang memang dirancang untuk dihapus.

## Isi

| Berkas | Fungsi |
|---|---|
| [PRD.md](PRD.md) | dokumen produk: masalah, kriteria sukses, non-goal, arsitektur, gerbang kualitas |
| [prd.json](prd.json) | backlog 26 story, format JSON tracker ralph-tui |
| [AGENTS.md](AGENTS.md) | aturan tetap yang dibaca agen di **setiap** iterasi |
| [.ralph-tui/config.toml](.ralph-tui/config.toml) | konfigurasi agen, loop, error handling |
| [.claude/skills/](.claude/skills/) | 6 skill project-scoped (lihat di bawah) |

Keempat berkas ini pindah ke **root repo baru** saat boilerplate dipakai — `prd.json` dan
`.ralph-tui/` harus berada di root, bukan di subdirektori.

---

## Dua cara pakai

Bedakan dulu, karena perintahnya berbeda:

| | Kapan | Yang dijalankan |
|---|---|---|
| **A. Membangun boilerplate-nya** | repo masih kosong, 26 story di `prd.json` belum dikerjakan | `ralph-tui run --prd ./prd.json` |
| **B. Memulai aplikasi baru dari boilerplate jadi** | boilerplate sudah selesai, mau bikin proyek baru | clone → rename → `create-prd` untuk backlog aplikasi baru |

---

## Menjalankan aplikasi (tanpa container)

Prasyarat: PHP >= 8.3 dan Composer. Untuk SPA nanti juga Node >= 20.

```bash
composer install
cp .env.example .env          # PowerShell: Copy-Item .env.example .env
php artisan key:generate
php artisan --version         # harus Laravel 13.x
php artisan serve             # http://127.0.0.1:8000
```

`.env.example` sudah menunjuk ke Postgres dan Redis dengan nama host compose (`pgsql`, `redis`),
jadi jalur di atas hanya cocok untuk halaman yang tidak menyentuh DB/cache. Untuk pemakaian
sehari-hari pakai Podman (bagian berikutnya); kalau memang mau jalan di host, ganti `DB_HOST` dan
`REDIS_HOST` menjadi `127.0.0.1` karena port keduanya sudah dipublikasikan compose.

`composer.json` mengunci `config.platform.php = "8.3.0"` supaya resolusi dependensi selalu memakai
versi yang jalan di PHP 8.3 (Symfony 7.4, bukan 8.1 yang menuntut PHP >= 8.4.1) meskipun mesin
pengembang memakai PHP 8.4. Jangan hapus pin itu tanpa menaikkan `require.php` sekaligus.

`.env` tidak pernah ikut ter-commit; `.env.example` hanya berisi placeholder, tidak ada secret asli.

---

## Menjalankan dengan Podman (dev)

```bash
podman compose up -d
podman compose exec app php artisan migrate --seed
podman compose logs -f app
podman compose down            # tambah -v untuk ikut menghapus volume data
```

Service yang naik: `app` (Octane + FrankenPHP, port 80), `horizon`, `reverb` (8080), `scheduler`,
`pgsql` (5432), `redis` (6379), `minio` (9000 API / 9001 konsol), dan `createbucket` — one-shot
yang membuat bucket dev lalu keluar dengan status 0. Container `createbucket` berstatus
`Exited (0)` itu normal, bukan kegagalan.

Tiga hal yang menentukan compose ini jalan atau tidak:

- **UID host.** `app`, `horizon`, `reverb`, dan `scheduler` bind-mount source repo dan memakai
  `userns_mode: keep-id`. Kalau UID host bukan 1000, tulis dulu ke `.env` supaya file yang
  ditulis container tetap milik pengguna host:
  ```bash
  echo "HOST_UID=$(id -u)" >> .env
  echo "HOST_GID=$(id -g)" >> .env
  ```
- **Port 80.** Rootless Podman menolak bind port < 1024 sampai
  `net.ipv4.ip_unprivileged_port_start=80` dipasang (lihat skill `podman-quadlet-ops`).
- **Lokasi repo di Windows.** Taruh repo di dalam WSL2. `podman machine` hanya mem-mount
  `%USERPROFILE%`, jadi bind mount dari drive lain bisa gagal tanpa pesan yang jelas.

`horizon` dan `reverb` memakai `container/php/worker-entrypoint.sh`: entrypoint menunggu sampai
perintah artisan-nya benar-benar ada (paketnya baru dipasang di US-013 dan US-014) lalu `exec`
supaya SIGTERM diteruskan ke proses PHP. Tanpa penjaga itu container keluar dengan status bukan
nol dan Podman memasukkannya ke restart loop, sehingga `podman compose up -d` terlihat rusak
padahal komposisinya benar.

Semua image di `compose.yaml` **wajib** memakai nama registry lengkap (`docker.io/...`). Short
name memicu prompt pemilihan registry yang tidak terlihat pada `up -d`, dan perintahnya hanya
tampak menggantung.

## Membangun image produksi

```bash
podman build -f container/Containerfile.prod -t myapp:latest .
```

Image produksi adalah multi-stage:
- **Stage 1 (frontend-builder):** Node 20, membangun SPA dengan Vite ke `public/build`
- **Stage 2 (akhir):** FrankenPHP minimal, composer install `--no-dev`, cache pre-warmed

**Penting: variabel `VITE_*` dibakukan saat build, bukan dibaca saat runtime.**

Jadi kalau perlu mengubah `VITE_REVERB_HOST` atau variable frontend lainnya, harus rebuild image.
Untuk lingkungan yang berbeda (dev/staging/prod), buat image terpisah atau sediakan nilai yang
fleksibel di Vite config:

```bash
# Contoh: set VITE_* saat build
podman build \
  --build-arg VITE_REVERB_HOST=reverb.example.com \
  --build-arg VITE_REVERB_PORT=443 \
  -f container/Containerfile.prod -t myapp:prod .
```

Agar arg `VITE_*` terpakai, tambahkan `ARG` di Containerfile.prod sebelum npm build:

```dockerfile
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
```

Lalu passing saat build-push (CI workflow atau deploy manual).

### Octane + FrankenPHP

Service `app` menjalankan `php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80
--admin-port=2019 --max-requests=500`. Health check container memanggil `/up` bawaan Laravel, jadi
`podman compose up -d` baru menandai `app` sehat setelah aplikasi benar-benar merespons:

```bash
curl -fsS http://localhost/up
```

- `OCTANE_SERVER=frankenphp` **wajib** ada di `.env`. Default di `config/octane.php` adalah
  `roadrunner`; tanpa variabel itu perintah lain (`octane:reload`, `octane:status`) memeriksa
  server yang salah.
- `--admin-port` harus disebut eksplisit. Octane menghitungnya `2019 + (port - 8000)`, yang untuk
  port 80 bernilai negatif dan menggagalkan start dengan "Unable to determine admin port".
- Bind port 80 sebagai UID 1000 aman karena binary `/usr/local/bin/frankenphp` di image punya
  `cap_net_bind_service`. Kalau muncul `bind: permission denied`, yang bermasalah adalah sisi
  host (`net.ipv4.ip_unprivileged_port_start`), bukan container.
- **Kode tidak di-reload otomatis.** Aplikasi boot sekali per worker, jadi setelah mengubah kode
  PHP jalankan:
  ```bash
  podman compose exec app php artisan octane:reload
  ```
  Aturan keamanan state antar-request ada di skill `octane-worker-safety`.

### Postgres + Redis

Satu database (Postgres) dan tiga jalur Redis:

| Variabel | Nilai | Dipakai untuk |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | seluruh data aplikasi |
| `CACHE_STORE` | `redis` | `Cache::` (koneksi Redis `cache`, DB 1) |
| `SESSION_DRIVER` | `redis` | session pengguna (koneksi Redis `default`, DB 0) |
| `QUEUE_CONNECTION` | `redis` | job & Horizon (koneksi Redis `default`, DB 0) |

Nilainya ada di `.env.example` **dan** disebut ulang di blok `environment` `compose.yaml`, supaya
container tidak ikut salah kalau `.env` di host masih memakai nilai lama.

```bash
podman compose exec app php artisan migrate --force
```

Migrasi bawaan `create_cache_table` dan `create_jobs_table` sengaja dipertahankan: keduanya
menyediakan jalur mundur ke driver `database` kalau Redis sedang dimatikan untuk debugging.

Terkonfigurasi tidak sama dengan terpakai, jadi `tests/Feature/RedisBackedServicesTest.php`
membuktikan ketiganya menyentuh Redis sungguhan:

```bash
podman exec ajb-ricemill-app-1 vendor/bin/phpunit --filter RedisBackedServices
```

Test itu **tidak memakai `Queue::fake()`** — fake hanya mencatat dispatch di memori dan tetap
hijau walaupun Redis mati. Yang dilakukan: `pushOn()` job nyata, membaca `LLEN queues:<nama>`
langsung lewat facade `Redis`, `pop()` payload-nya kembali, lalu `fire()`. `phpunit.xml` memaksa
`CACHE_STORE=array` dan `QUEUE_CONNECTION=sync` supaya test lain cepat, jadi test ini menyebut
`Cache::store('redis')`, `Session::driver('redis')`, dan `Queue::connection('redis')` eksplisit.

### `env()` hanya boleh dipanggil dari `config/`

Produksi menjalankan `php artisan config:cache`. Setelah config ter-cache, Laravel **tidak memuat
`.env` sama sekali**, sehingga `env()` di luar `config/` mengembalikan `null` — tanpa error, tanpa
log. Di Octane efeknya lebih halus lagi: nilai yang terbaca saat boot worker pertama dibekukan
untuk seluruh request berikutnya.

Aturannya: baca env di `config/*.php`, konsumsi lewat `config('...')` di tempat lain.

```php
// config/ricemill.php
return ['timbangan_url' => env('TIMBANGAN_URL')];

// app/Services/Timbangan.php
config('ricemill.timbangan_url');   // bukan env('TIMBANGAN_URL')
```

`tests/Unit/NoEnvOutsideConfigTest.php` memindai `app/`, `routes/`, dan `database/` lalu gagal
dengan daftar `berkas:baris` begitu ada `env(` baru. Regexnya sengaja mengabaikan `getenv()`,
`$this->env()`, dan `Foo::env()`.

```bash
podman compose exec app vendor/bin/phpunit --filter NoEnvOutsideConfig
```

---

Struktur direktori mengikuti [PRD §7](PRD.md#7-struktur-repo): `frontend/` (Vue 3, build ke
`public/build`), `container/` (Containerfile, Caddyfile, `php/octane.ini`), `deploy/quadlet/`
(unit systemd), `bin/` (doctor, rename-project, deploy), `docs/` (runbook & aturan Octane).

---

## Agent skills

### Project-scoped (ikut di repo, tidak perlu instalasi)

`.claude/skills/` — ditemukan otomatis oleh Claude Code, dan dirujuk agen lain lewat
`skills_dir` di `.ralph-tui/config.toml`:

| Skill | Isi |
|---|---|
| `octane-worker-safety` | jebakan worker mode: singleton bocor, `env()` setelah `config:cache`, cara membuktikannya dengan `--workers=1` |
| `podman-quadlet-ops` | rootless, `linger`, port < 1024, FQIN, `keep-id`, unit Quadlet, backup volume |
| `sanctum-spa-auth` | cookie mode, CSRF, diagnosis 419/401, otorisasi channel berbasis permission |
| `queue-realtime-contract` | API Resource = state Pinia = payload broadcast, pemisahan queue, desain job idempoten |
| `vue-pinia-realtime` | listener terpusat di store, proxy Vite, `VITE_*` di-bake saat build |
| `verify-gates` | kriteria penerimaan yang dapat dieksekusi, larangan melonggarkan gerbang |

Skill ini menyimpan pengetahuan yang **mahal ditemukan ulang tiap iterasi** — agen mulai dengan
context kosong setiap kali, jadi tanpa ini ia akan menemukan lubang yang sama berulang-ulang.

### Dari marketplace (opsional, sekali per mesin)

```bash
/plugin install laravel-boost      # MCP server: artisan, Tinker, dokumentasi Laravel versi terpasang
/plugin install php-lsp            # Intelephense: navigasi simbol & diagnostik PHP
```

Jalankan di dalam sesi Claude Code (perintah slash, bukan shell). Keduanya generik untuk Laravel;
tidak ada plugin marketplace untuk Octane, FrankenPHP, Podman/Quadlet, Reverb, atau Vuetify —
itulah sebabnya keenam skill di atas ditulis sendiri.

---

## A. Membangun boilerplate

```bash
npm install -g ralph-tui        # atau: bun add -g ralph-tui
ralph-tui setup                 # wizard: pilih agen, tracker, dan model
ralph-tui config validate       # pastikan config.toml terbaca
```

Jalankan backlog:

```bash
ralph-tui run --prd ./prd.json
```

Loop mengambil satu story `passes: false` yang seluruh `dependsOn`-nya sudah `true`,
mengerjakannya dengan context window baru, memverifikasi, menandai `passes: true`, lalu mengulang
sampai backlog habis.

---

## B. Clone + rename untuk proyek baru

Misal proyek baru bernama `acme-app`.

### Cara 1 — repo template GitHub (paling bersih)

Riwayat commit boilerplate tidak ikut terbawa:

```bash
gh repo create acme-app --template <org>/laravel13-frankenphp-starter --private --clone
cd acme-app
bin/rename-project acme-app
```

### Cara 2 — clone lalu putuskan riwayat

```bash
git clone --depth 1 https://github.com/<org>/laravel13-frankenphp-starter.git acme-app
cd acme-app
rm -rf .git && git init && git branch -M main
bin/rename-project acme-app
git add -A && git commit -m "chore: init acme-app dari laravel13-frankenphp-starter"
git remote add origin git@github.com:<org>/acme-app.git && git push -u origin main
```

> `rm -rf .git` itu langkah yang menentukan. Tanpa itu, proyek baru mewarisi seluruh riwayat
> boilerplate dan `git push` pertama akan menabrak remote yang salah.

### Cara 3 — tanpa git sama sekali

```bash
npx degit <org>/laravel13-frankenphp-starter acme-app
cd acme-app && git init && git branch -M main
bin/rename-project acme-app
```

### Setelah rename

```bash
bin/doctor                                # cek prasyarat & bentrok port
cp .env.example .env && php artisan key:generate
podman compose up -d
podman compose exec app php artisan migrate --seed
composer verify
```

Lalu buang bagian contoh dan buat backlog aplikasi sendiri:

```bash
cat docs/REMOVE-DEMO.md                   # daftar berkas berlabel @demo
ralph-tui create-prd --chat --agent claude --model opus --force
ralph-tui run --prd ./prd.json --serial
```

`--force` diperlukan karena `prd.json` bawaan boilerplate (26 story yang sudah `passes: true`)
akan ditimpa oleh backlog aplikasi baru.

### Kalau `bin/rename-project` belum ada

Skrip itu dibangun oleh story **US-024** dan belum ada sampai backlog dijalankan. Sebelum itu,
ganti manual — periksa dulu sebarannya, baru ganti:

```bash
rg -l 'laravel13-frankenphp-starter'                      # lihat berkas yang terdampak
rg -l 'laravel13-frankenphp-starter' | xargs sed -i 's/laravel13-frankenphp-starter/acme-app/g'
rg 'laravel13-frankenphp-starter'                         # harus nihil
```

Yang wajib ikut berubah dan gampang terlewat: `composer.json` (name), `frontend/package.json`,
`compose.yaml` (nama volume & container), `deploy/quadlet/*.container` (nama unit systemd),
dan `.env.example` (`APP_NAME`, `DB_DATABASE`, `AWS_BUCKET`). Unit Quadlet yang namanya tidak
ikut diganti akan bentrok dengan proyek lain di VPS yang sama.

Pada Windows tanpa `sed`, pakai PowerShell:

```powershell
Get-ChildItem -Recurse -File -Exclude .git |
  Select-String -List 'laravel13-frankenphp-starter' |
  ForEach-Object { (Get-Content $_.Path -Raw) -replace 'laravel13-frankenphp-starter','acme-app' |
                   Set-Content $_.Path -Encoding utf8 }
```

---

## Contoh perintah

### Menjalankan loop

```bash
# jalan normal, memakai .ralph-tui/config.toml
ralph-tui run --prd ./prd.json

# tentukan agen dan model dari CLI (menimpa config.toml)
ralph-tui run --prd ./prd.json --agent claude --model opus

# agen lain
ralph-tui run --prd ./prd.json --agent opencode --model google/gemini-2.5-pro

# batasi jumlah iterasi — berguna saat mencoba backlog pertama kali
ralph-tui run --prd ./prd.json --iterations 3

# tanpa TUI, untuk CI atau sesi SSH panjang
ralph-tui run --prd ./prd.json --headless --output-dir .ralph-tui/iterations

# kerjakan sebagian story saja (indeks, bukan id)
ralph-tui run --prd ./prd.json --task-range 1-5

# paksa berurutan; hindari paralel pada backlog ini
ralph-tui run --prd ./prd.json --serial
```

> **Pakai `--serial`.** Story di backlog ini banyak yang menyentuh berkas yang sama
> (`.env.example`, `compose.yaml`, `routes/api.php`). Eksekusi paralel membuat dua agen menulis
> berkas yang sama pada saat bersamaan, dan konfliknya baru terlihat setelah beberapa iterasi.

### Membuat PRD baru (untuk proyek lain)

```bash
# mode chat berbantuan AI — paling direkomendasikan
ralph-tui create-prd --chat

# tentukan agen dan model
ralph-tui create-prd --chat --agent claude --model opus
ralph-tui create-prd --chat --agent opencode --model google/gemini-2.5-pro --variant high

# arahkan output, timpa berkas lama
ralph-tui create-prd --chat --output ./docs/prds --force

# mode template tanpa AI, tentukan jumlah story
ralph-tui create-prd --stories 8
```

Setelah PRD dibuat, ralph-tui menanyakan format task: **JSON** (menghasilkan `prd.json`),
**Beads**, atau lewati.

### Inspeksi

```bash
ralph-tui template show        # lihat prompt template yang sedang dipakai
ralph-tui config validate      # validasi config.toml
ralph-tui resume               # lanjutkan sesi yang terputus
```

---

## Konfigurasi agen & model

Dua tempat, CLI menang atas berkas:

```toml
# .ralph-tui/config.toml
agent = "claude"

[agentOptions]
model = "claude-opus-5"
```

```bash
# menimpa untuk satu kali jalan
ralph-tui run --prd ./prd.json --agent claude --model opus
```

Format nama model mengikuti agen yang dipakai — `opus` untuk Claude Code,
`google/gemini-2.5-pro` untuk OpenCode. Agen yang didukung mencakup Claude Code, OpenCode,
Factory Droid, Cursor CLI, Gemini CLI, Codex, dan Kiro CLI.

---

## Sebelum menjalankan loop

1. **Baca [AGENTS.md](AGENTS.md).** Berkas itu satu-satunya ingatan lintas-iterasi selain kode dan
   git history. Kalau ada aturan proyek yang tidak tertulis di sana, agen tidak akan tahu.
2. **`autoCommit = false`** disengaja. Backlog ini menyentuh migrasi DB dan unit systemd; commit
   dilakukan setelah `composer verify` hijau dan hasilnya dilihat.
3. **Story yang tidak selesai dalam satu iterasi berarti terlalu besar.** Pecah story-nya di
   `prd.json`, jangan naikkan `maxIterations` — loop akan berputar tanpa kemajuan.
4. Pantau `.ralph-tui/progress.md`. Bagian `## Perlu keputusan manusia` di situ adalah tempat agen
   menaruh hal yang tidak boleh dikarangnya sendiri.

## Verifikasi manual

```bash
bin/doctor                                # prasyarat & bentrok port (US-023)
podman compose up -d
composer verify                           # pint --test + seluruh test suite
npm --prefix frontend run verify          # eslint + vue-tsc + vitest
```

Ganti `<org>` pada perintah clone dengan organisasi/akun tempat boilerplate ini didorong.
