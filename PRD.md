# PRD — `laravel13-frankenphp-starter`

Boilerplate reusable untuk aplikasi web full-stack: Laravel 13 di FrankenPHP (Octane worker mode),
realtime via Reverb, background job via Horizon, SPA Vue 3 + Vuetify 3, dijalankan dengan Podman.

Dokumen ini **tidak memuat ruang lingkup bisnis apa pun**. Stack diambil dari `PLAN.md`
proyek Ricemill, tetapi seluruh entitas domain (batch, rendemen, antrian truk, scale-agent)
sengaja dibuang. Yang tersisa hanya kerangka teknis yang dipakai ulang di proyek berikutnya.

---

## 1. Masalah yang Diselesaikan

Setiap proyek baru dengan stack ini menghabiskan 1–2 minggu untuk pekerjaan yang selalu sama:
menyambungkan Octane, Reverb, Horizon, Sanctum, RBAC, S3, SPA, container, dan pipeline deploy —
lalu menemukan lubang yang sama berulang kali (state bocor antar-request di worker mode, channel
privat tanpa cek permission, cookie SPA tidak jalan, port bentrok, service mati setelah reboot).

Boilerplate ini memindahkan pekerjaan itu ke sekali saja, sudah teruji, dan siap di-`clone`.

## 2. Kriteria Sukses

| # | Kriteria | Cara ukur |
|---|---|---|
| S1 | Dari `git clone` sampai aplikasi hidup di mesin baru | ≤ 15 menit, ≤ 3 perintah |
| S2 | Seluruh stack terbukti tersambung, bukan sekadar terpasang | satu alur demo: request → job → broadcast → UI ter-update, dijalankan oleh test otomatis |
| S3 | Deploy produksi berulang tanpa langkah manual tak tercatat | `bin/deploy` + runbook; reboot VPS → semua service naik sendiri |
| S4 | Dipakai ulang tanpa sisa identitas proyek lama | `bin/rename-project` mengganti seluruh placeholder; `rg <nama-lama>` nihil |
| S5 | Dibangun dan dirawat oleh agen otonom | seluruh backlog jalan lewat ralph-tui tanpa intervensi per-story |

## 3. Non-Goal

Ditulis eksplisit supaya tidak merembet:

- **Tidak ada domain bisnis.** Tidak ada entitas selain `User`, `Role`, `Permission`, dan satu
  resource contoh (`Item`) yang memang dirancang untuk dihapus.
- Tidak multi-tenant, tidak billing, tidak i18n, tidak tema/desain kustom.
- Tidak mendukung Docker. **Podman-only** (lihat §5).
- Tidak menyediakan CI/CD selain satu workflow build+test.
- Tidak menargetkan multi-node. Satu VPS; jalur ke multi-node dicatat, tidak diimplementasikan.

## 4. Prinsip Desain

1. **Sekali jalan, bukan sekali pasang.** Setiap komponen wajib punya satu test yang membuktikan
   ia benar-benar tersambung ke komponen tetangganya.
2. **Aman secara default.** Channel privat memeriksa permission, bukan hanya identitas. Rate limit
   menyala. Secret tidak pernah masuk bundle frontend.
3. **Bisa dihapus.** Setiap bagian contoh diberi label `@demo` dan terdaftar di `docs/REMOVE-DEMO.md`.
4. **Ramah worker mode.** Aturan anti-bocor state Octane ditegakkan lewat test, bukan lewat imbauan.
5. **Bisa dikerjakan agen.** Setiap unit kerja punya kriteria penerimaan yang dapat diverifikasi
   satu perintah shell.

---

## 5. Stack (mengikuti `PLAN.md`)

| Layer | Pilihan | Catatan |
|---|---|---|
| Backend | Laravel 13, PHP 8.3 | |
| App server | FrankenPHP via Laravel Octane (worker mode) | TLS + HTTP/2 + HTTP/3, sekaligus static server untuk build SPA |
| Realtime | Laravel Reverb + Laravel Echo | self-hosted |
| Queue | Redis + Laravel Horizon | dua queue: `default`, `priority` |
| Cache / session / lock | Redis | |
| DB | PostgreSQL 17 | |
| Auth | Laravel Sanctum — mode cookie/stateful | SPA satu origin, tanpa CORS |
| RBAC | spatie/laravel-permission | dipakai di route, policy, **dan** channel |
| Object storage | S3-compatible (MinIO lokal / S3 produksi) | upload lewat presigned URL |
| Frontend | Vue 3 + Vuetify 3 + Pinia + Vue Router + Vite | di `frontend/`, build ke `public/build` |
| Container | **Podman** — compose (dev) / Quadlet + systemd (prod) | rootless |
| OS produksi | Ubuntu 24.04 LTS | Podman ≥ 4.4 wajib (Quadlet) |
| Test | Pest, Larastan, Pint, Vitest, ESLint | gerbang kualitas di CI |

Keputusan yang diwarisi dari `PLAN.md` dan **tidak** dibuka ulang di boilerplate:
Sanctum mode cookie (bukan token), SPA satu origin, Podman (bukan Docker), Quadlet di produksi.

---

## 6. Arsitektur

```
   Browser — Vue 3 + Vuetify + Pinia (SPA)
        │ REST /api/*  (Sanctum cookie)     │ wss:// (Echo)
        ▼                                   ▼
  ┌────────────────────────────┐   ┌──────────────────┐
  │ frankenphp (octane)        │   │ reverb (ws)      │
  │ · API Laravel              │   │ · /broadcasting/auth
  │ · serve public/ (SPA)      │   └────────▲─────────┘
  └──────────┬─────────────────┘            │
             │ dispatch job                  │ push event
             ▼                               │
        ┌──────────┐                         │
        │  Redis   │─────────────────────────┘
        └────┬─────┘   (queue + pub/sub)
             │
     ┌───────┴────────┐   ┌────────────────┐
     │ horizon        │   │ scheduler      │
     └───────┬────────┘   └────────────────┘
             ▼
  ┌──────────────────┐   ┌──────────────┐
  │ PostgreSQL 17    │   │ MinIO / S3   │
  └──────────────────┘   └──────────────┘
```

Lima tipe proses dari satu image: `app` (octane), `horizon`, `reverb`, `scheduler`, plus
dependensi (`pgsql`, `redis`, `minio`). Tidak digabung dalam satu container.

### Kontrak lintas-lapis

**API Resource = bentuk state Pinia = payload broadcast.** Satu bentuk data saja, sehingga event
realtime bisa langsung di-commit ke store tanpa transformasi berbeda. `broadcastWith()` wajib
memakai ulang API Resource yang sama.

---

## 7. Struktur Repo

```
<project>/
├── app/
│   ├── Events/                    ExampleUpdated (@demo)
│   ├── Jobs/                      ExampleLongJob (@demo)
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   ├── Middleware/
│   │   └── Resources/
│   ├── Models/                    User, Item (@demo)
│   ├── Policies/
│   └── Providers/
├── frontend/                      Vue 3 + Vuetify 3 + Pinia + Router
│   ├── src/stores/                auth.ts, example.ts (@demo)
│   ├── src/plugins/echo.ts
│   └── vite.config.ts             outDir '../public/build', proxy /api /sanctum /broadcasting
├── container/
│   ├── Containerfile              dev
│   ├── Containerfile.prod         multi-stage (SPA + PHP)
│   ├── Caddyfile
│   └── php/octane.ini
├── deploy/quadlet/                unit systemd produksi
├── bin/
│   ├── doctor                     cek prasyarat & port bentrok
│   ├── rename-project             ganti placeholder
│   └── deploy                     auto-update + migrate + reload
├── docs/
│   ├── RUNBOOK.md                 deploy, rollback, backup, insiden
│   ├── REMOVE-DEMO.md             cara membuang seluruh contoh
│   └── OCTANE-RULES.md            aturan worker mode
├── .ralph-tui/config.toml
├── AGENTS.md
├── prd.json
├── compose.yaml
└── .env.example
```

---

## 8. Gerbang Kualitas

Satu perintah harus menutup semuanya — dipakai manusia maupun agen:

```bash
composer verify      # pint --test && larastan && pest
npm --prefix frontend run verify   # eslint && vue-tsc --noEmit && vitest run
```

| Gerbang | Alat | Ambang |
|---|---|---|
| Format PHP | Pint | tanpa diff |
| Analisis statis | Larastan | level 6, nol error |
| Test PHP | Pest | seluruh test hijau; coverage tidak dipaksakan |
| Lint/typecheck FE | ESLint + vue-tsc | nol error |
| Test FE | Vitest | seluruh test hijau |

Wajib ada minimal satu test untuk tiap klaim sambungan: auth cookie, otorisasi channel,
job → broadcast, presigned upload, dan isolasi state Octane.

---

## 9. Definition of Done (tingkat proyek)

Boilerplate dinyatakan selesai bila di mesin bersih:

1. `bin/doctor` lolos.
2. `podman compose up -d && composer verify` hijau.
3. Login dari SPA berhasil; user tanpa permission ditolak saat subscribe channel privat.
4. Menekan tombol demo → job masuk Horizon → event Reverb → UI ter-update tanpa reload,
   dan alur itu dijalankan ulang oleh satu test otomatis.
5. Upload file lewat presigned URL masuk MinIO tanpa melewati worker PHP.
6. `bin/deploy` ke VPS uji sukses; VPS di-reboot → seluruh service naik sendiri.
7. `bin/rename-project acme-app` menghasilkan repo tanpa jejak nama lama.
8. Menghapus semua yang berlabel `@demo` sesuai `docs/REMOVE-DEMO.md` tetap menyisakan
   `composer verify` hijau.

---

## 10. Kompatibilitas ralph-tui

Backlog dijalankan oleh [ralph-tui](https://ralph-tui.com/) — loop agen otonom yang mengambil satu
story per iterasi dengan **context window baru setiap kali**. Konsekuensinya dibawa ke desain repo:

### Artefak wajib

| File | Fungsi |
|---|---|
| `prd.json` | tracker story (format JSON tracker ralph-tui) |
| `.ralph-tui/config.toml` | konfigurasi agen, loop, error handling |
| `AGENTS.md` | aturan tetap yang harus dibaca agen di setiap iterasi |
| `.ralph-tui/progress.md` | catatan lintas-iterasi (ditulis loop) |

### Skema `prd.json`

Root: `name`, `description`, `branchName`, `userStories[]`, `metadata`.
Tiap story: `id`, `title`, `description`, `acceptanceCriteria[]`, `priority` (1 tertinggi),
`passes` (boolean — penanda selesai), `labels[]`, `dependsOn[]`, `notes`.

Story hanya boleh dipilih bila seluruh `dependsOn` sudah `passes: true`.

### Aturan penulisan story (agar loop tidak macet)

1. **Satu story = satu iterasi.** Bila sebuah story tidak selesai dalam satu context window,
   itu tanda story-nya terlalu besar — pecah, jangan naikkan `maxIterations`.
2. **Kriteria penerimaan harus dapat dieksekusi.** Tulis perintah yang keluar dengan status 0,
   bukan kalimat opini. `composer verify` lebih baik daripada "kode rapi".
3. **`dependsOn` membentuk DAG**, tidak boleh melingkar. Urutan ditentukan dependensi + `priority`,
   bukan urutan nomor.
4. **Tidak ada state tersembunyi antar-story.** Setiap story menyebut file yang disentuhnya, karena
   agen iterasi berikutnya tidak mengingat apa pun dari iterasi sebelumnya.
5. **Guardrail tinggal di `AGENTS.md`, bukan di deskripsi story.** Aturan yang berlaku untuk semua
   iterasi (jebakan Octane, larangan `env()`, wajib cek permission di channel) ditulis sekali di
   sana supaya tidak diulang 26 kali dan tidak sempat menyimpang.
6. **`autoCommit = false`.** Migrasi DB dan perubahan unit systemd terlalu berisiko untuk di-commit
   tanpa dilihat. Commit dilakukan setelah `composer verify` hijau.

### Menjalankan

```bash
ralph-tui setup                 # sekali, memilih agen & tracker
ralph-tui run --prd ./prd.json
```

> Nama kunci `[trackerOptions]` untuk tracker JSON belum diverifikasi terhadap versi ralph-tui
> yang terpasang. Jalankan `ralph-tui config validate` (atau `ralph-tui template show`) sekali
> di awal; kalau kunci berbeda, sesuaikan `.ralph-tui/config.toml` — struktur `prd.json`-nya
> sendiri sudah sesuai skema resmi JSON tracker.

---

## 11. Backlog

26 story, tersimpan di `prd.json`. Ringkasan per kelompok:

| Kelompok | Story | Inti |
|---|---|---|
| Fondasi | US-001…005 | scaffold, container dev, Octane boot, konfigurasi, Postgres+Redis |
| Kualitas | US-006, US-007 | Pest/Pint/Larastan, CI |
| Auth & RBAC | US-008, US-009 | Sanctum cookie, spatie permission |
| Frontend | US-010…012 | scaffold SPA, proxy + fallback, store auth |
| Queue & Realtime | US-013…016 | Horizon, Reverb, channel ber-permission, job → broadcast → store |
| Storage & Ops | US-017…019 | presigned upload, observability, guard state Octane |
| Deploy | US-020…022 | image produksi, Quadlet, runbook |
| DX | US-023, US-024 | `bin/doctor`, `bin/rename-project` |
| Opsional | US-025, US-026 | modul webhook HMAC generik, vertical demo end-to-end |

Jalur kritis: **US-001 → 002 → 003 → 005 → 008 → 009 → 015**. Story otorisasi channel (US-015)
sengaja diletakkan di jalur kritis, bukan sebagai pekerjaan susulan — channel privat tanpa cek
permission adalah lubang yang paling mudah lolos ke produksi.

---

## 12. Risiko

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Kebocoran state antar-request di Octane | data user bocor ke user lain | US-019: test khusus + `docs/OCTANE-RULES.md` + `AGENTS.md` |
| Channel privat tanpa cek permission | data terbaca pihak tak berhak lewat WebSocket | US-015 di jalur kritis, dengan test negatif |
| Podman < 4.4 di server | Quadlet tidak ada, §deploy gagal total | `bin/doctor` memeriksa versi sebelum apa pun |
| `linger` tidak aktif | seluruh service mati setelah logout/reboot | masuk kriteria penerimaan US-021 (uji reboot) |
| Bagian demo ikut terbawa ke produksi | kode mati & permukaan serangan tak perlu | label `@demo` + `docs/REMOVE-DEMO.md` + DoD #8 |
| Story terlalu besar untuk satu context window | loop ralph berputar tanpa kemajuan | aturan §10.1; pecah story, jangan naikkan iterasi |
