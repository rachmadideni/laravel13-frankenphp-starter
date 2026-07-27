---
name: verify-gates
description: "Gerbang kualitas proyek: Pest, Pint, Larastan, ESLint, vue-tsc, Vitest, dan cara menulis kriteria penerimaan yang dapat dieksekusi. Gunakan sebelum menandai pekerjaan selesai, saat menambah test, saat CI merah, atau saat muncul godaan menurunkan level analisis statis, menambah baseline, memakai --no-verify, atau menandai test skip agar pipeline hijau."
---

# Gerbang Kualitas

Satu perintah menutup satu sisi:

```bash
composer verify                      # pint --test && larastan && pest
npm --prefix frontend run verify     # eslint && vue-tsc --noEmit && vitest run
```

Pekerjaan **belum selesai** sampai keduanya keluar dengan status 0.

## Larangan

Dilarang membuat pipeline hijau dengan cara berikut:

- menurunkan level Larastan,
- menambah entri baseline,
- `git commit --no-verify`,
- menandai test `skip`/`todo` untuk menyembunyikan kegagalan,
- melonggarkan aturan ESLint alih-alih memperbaiki kodenya.

Semua itu memindahkan kegagalan dari CI ke produksi. Perbaiki penyebabnya.

## Kriteria penerimaan harus dapat dieksekusi

Setiap unit kerja diverifikasi oleh perintah yang keluar dengan status 0, bukan oleh penilaian.

| ❌ tidak dapat diverifikasi | ✅ dapat diverifikasi |
|---|---|
| "kode rapi dan mudah dibaca" | `composer verify` keluar dengan status 0 |
| "auth sudah jalan" | test: tanpa sesi → 401; setelah login → 200 |
| "channel aman" | test negatif: user tanpa permission → 403 dari `/broadcasting/auth` |
| "upload berfungsi" | test integrasi: objek benar-benar ada di bucket setelah unggah |

Ini penting khusus di repo ini karena pekerjaan dijalankan agen otonom dengan context window baru
setiap iterasi: agen berikutnya tidak bisa menilai "rapi", tapi bisa menjalankan perintah.

## Test yang membuktikan sambungan, bukan pemasangan

Satu test untuk tiap klaim sambungan lintas-komponen:

| Klaim | Test yang membuktikannya |
|---|---|
| Auth cookie jalan | csrf-cookie → login → `/api/me` 200; logout → 401 |
| RBAC jalan | user tanpa permission → 403; dengan permission → 200 |
| Channel aman | **test negatif** 403 pada `/broadcasting/auth` |
| Queue → broadcast | job sungguhan berjalan, event tersiar dengan payload = API Resource |
| Storage jalan | unggah lewat presigned URL, objek ada di bucket |
| Octane bersih | dua request user berbeda, tidak ada data menular |

`Queue::fake()` dan `Event::fake()` membuktikan sesuatu *dipanggil*, bukan bahwa rantainya
*bekerja*. Minimal satu test per rantai harus berjalan sungguhan.

## Test negatif lebih berharga

Test positif hampir selalu ditulis. Yang menangkap lubang keamanan adalah pasangannya: pengguna
tanpa hak **ditolak**. Setiap fitur berotorisasi wajib punya keduanya.

## Sebelum menandai selesai

```bash
composer verify
npm --prefix frontend run verify
git status --porcelain          # tidak ada berkas sementara yang tertinggal
```
