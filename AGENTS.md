# AGENTS.md

Aturan tetap untuk setiap iterasi agen. **Baca berkas ini lebih dulu, sebelum menyentuh kode.**
Setiap iterasi ralph-tui dimulai dengan context window kosong — berkas ini satu-satunya ingatan
yang tersisa dari iterasi sebelumnya, selain kode dan git history.

## Skill yang tersedia

Repo ini membawa skill project-scoped di `.claude/skills/`. Agen Claude Code menemukannya otomatis;
panggil yang relevan **sebelum** menulis kode, bukan setelah gagal.

| Skill | Panggil saat |
|---|---|
| `octane-worker-safety` | menyentuh service provider, singleton, binding, properti statis, `env()` |
| `podman-quadlet-ops` | `compose.yaml`, `Containerfile`, unit Quadlet, skrip provisioning/deploy, bentrok port |
| `sanctum-spa-auth` | endpoint auth, CSRF, `routes/channels.php`, `/broadcasting/auth`, 419/401 |
| `queue-realtime-contract` | membuat Job, Event, API Resource, supervisor Horizon, `broadcastWith` |
| `vue-pinia-realtime` | store Pinia, plugin Echo, komponen Vuetify, `vite.config.ts` |
| `verify-gates` | sebelum menandai story selesai, atau saat CI merah |

## Alur kerja satu iterasi

1. Baca `AGENTS.md` (berkas ini), lalu `.ralph-tui/progress.md`.
2. Ambil satu story dari `prd.json` yang `passes: false` dan seluruh `dependsOn`-nya sudah `true`.
3. Kerjakan **hanya story itu**. Jangan mengerjakan story lain "sekalian".
4. Jalankan seluruh kriteria penerimaannya. Semua harus keluar dengan status 0.
5. Jalankan `composer verify` dan, bila menyentuh frontend, `npm --prefix frontend run verify`.
6. Set `passes: true` pada story tersebut, dan catat ringkas di `.ralph-tui/progress.md`:
   apa yang dikerjakan, berkas apa yang disentuh, dan apa yang sengaja tidak dikerjakan.
7. Berhenti. Jangan lanjut ke story berikutnya dalam iterasi yang sama.

Bila sebuah story tidak selesai dalam satu iterasi: **jangan dipaksakan.** Pecah story itu di
`prd.json` menjadi beberapa story lebih kecil dengan `dependsOn` yang benar, catat alasannya di
`progress.md`, lalu berhenti.

## Larangan mutlak

Hal-hal berikut sudah diputuskan. Jangan diubah, jangan "diperbaiki", jangan ditawar ulang:

- **Podman, bukan Docker.** Tidak ada `docker-compose.yml`, tidak ada perintah `docker` di skrip.
- **Sanctum mode cookie/stateful**, bukan bearer token. Jangan menambah endpoint penerbit token.
- **SPA satu origin.** Jangan menambah konfigurasi CORS lebar untuk "menyelesaikan" masalah yang
  sebenarnya berasal dari salah menyetel proxy atau `SANCTUM_STATEFUL_DOMAINS`.
- **Nama image wajib lengkap (FQIN)**: `docker.io/library/postgres:17`, bukan `postgres:17`.
  Podman tidak menebak registry, dan short name membuat proses menggantung menunggu prompt.
- **Jangan longgarkan gerbang kualitas** untuk membuat test hijau: dilarang menurunkan level
  Larastan, menambah baseline, `--no-verify`, atau menandai test skip. Perbaiki penyebabnya.
- **Jangan menambah dependensi** di luar yang disebut story, kecuali memang tidak ada jalan lain —
  dan bila begitu, catat alasannya di `progress.md`.

## Aturan Octane (worker mode) — sumber bug termahal

Aplikasi boot sekali lalu melayani banyak request. State bertahan antar-request.

- Jangan menyimpan data milik request atau user di **singleton** maupun **properti statis**.
- Jangan menyimpan `$request` atau `Auth::user()` di properti class yang di-resolve sekali.
- Jangan memanggil `env()` di luar `config/`. Dengan config ter-cache, hasilnya `null`, dan
  gejalanya muncul jauh dari penyebabnya.
- Setiap penambahan singleton wajib disertai pertanyaan: apa isinya saat request berikutnya datang
  dari user yang berbeda? Bila jawabannya tidak pasti, jangan pakai singleton.

Detail dan alasannya ada di `docs/OCTANE-RULES.md`.

## Aturan keamanan

- **Channel privat wajib memeriksa permission**, bukan sekadar mencocokkan id user. Route API yang
  aman tidak menutup lubang WebSocket — keduanya jalur terpisah.
- Secret tidak pernah masuk bundle frontend. Hanya variabel `VITE_*` yang boleh terbaca browser,
  dan tidak satu pun boleh berisi secret.
- Perbandingan tanda tangan/HMAC memakai `hash_equals()`, tidak pernah `===`.
- Endpoint yang menerbitkan presigned URL wajib memeriksa autentikasi dan otorisasi.

## Kontrak data

**API Resource = bentuk state Pinia = payload broadcast.** Satu bentuk saja. `broadcastWith()`
memakai ulang API Resource yang sama, sehingga listener di store bisa langsung meng-commit payload
tanpa transformasi. Bila muncul dua bentuk berbeda untuk entitas yang sama, itu bug, bukan gaya.

## Kode contoh

Segala sesuatu yang bersifat demo diberi penanda `@demo` pada komentar berkas dan didaftarkan di
`docs/REMOVE-DEMO.md`. Boilerplate ini harus tetap bisa di-`verify` setelah seluruh demo dibuang.

## Bila ragu

Tulis pertanyaannya di `.ralph-tui/progress.md` di bawah judul `## Perlu keputusan manusia`,
kerjakan bagian yang tidak bergantung pada jawaban itu, dan jangan mengarang keputusan arsitektur
baru sendiri.
