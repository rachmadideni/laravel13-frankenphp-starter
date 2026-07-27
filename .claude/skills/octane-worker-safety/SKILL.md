---
name: octane-worker-safety
description: "Aturan dan pola verifikasi untuk Laravel Octane worker mode (FrankenPHP/Swoole/RoadRunner). Gunakan setiap kali menulis atau meninjau service provider, singleton, binding container, middleware, properti statis, penggunaan env(), atau saat mendiagnosis bug 'data user bocor ke user lain', 'nilai config jadi null setelah config:cache', 'perubahan kode tidak terlihat sampai restart', dan kebocoran memori pada worker. Berlaku untuk semua kode di app/, routes/, dan config/ pada proyek yang menjalankan octane:start."
---

# Octane Worker Safety

Aplikasi boot **sekali**, lalu satu proses PHP melayani ribuan request. State bertahan antar-request.
Ini sumber bug termahal di stack ini: data satu pengguna terlihat oleh pengguna lain, dan gejalanya
baru muncul di produksi saat trafik cukup padat.

## Aturan

1. **Jangan simpan data request-scoped di singleton.** `$this->app->singleton()` di-resolve sekali;
   isinya bertahan untuk request berikutnya dari user berbeda.
2. **Jangan simpan `$request`, `Auth::user()`, atau tenant/plant aktif di properti class** yang
   di-resolve sekali. Ambil ulang setiap kali dibutuhkan.
3. **Jangan pakai properti statis sebagai cache antar-request.** Kalau butuh cache, pakai
   `Cache::` (Redis) — bukan `static array $cache`.
4. **Jangan panggil `env()` di luar `config/`.** Dengan `config:cache`, hasilnya `null`. Gejalanya
   muncul jauh dari penyebabnya, biasanya baru di produksi.
5. **Batasi kebocoran memori** dengan `--max-requests=500`.
6. **Reset state paket pihak ketiga** yang menyimpan konfigurasi global, lewat listener
   `RequestReceived`/`RequestTerminated`.

## Pola yang benar

```php
// ❌ bocor: user pertama menempel selamanya
$this->app->singleton(ReportBuilder::class, fn ($app) =>
    new ReportBuilder(auth()->user()));

// ✅ bind ulang tiap resolve
$this->app->bind(ReportBuilder::class, fn ($app) =>
    new ReportBuilder($app['request']->user()));

// ✅ kalau memang harus singleton, jangan simpan identitas di dalamnya
$this->app->singleton(ReportBuilder::class, fn () => new ReportBuilder());
// lalu: app(ReportBuilder::class)->for($request->user())->build();
```

## Cara memverifikasi (jangan percaya pembacaan mata)

Test kebocoran antar-request — dua request berurutan dengan user berbeda:

```php
it('tidak membocorkan identitas user antar request', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($a)->getJson('/api/me')->assertJsonPath('data.id', $a->id);
    $this->actingAs($b)->getJson('/api/me')->assertJsonPath('data.id', $b->id);
});
```

Test ini lolos di php-fpm walaupun kode bocor. Untuk benar-benar membuktikan, jalankan aplikasi
dengan **satu worker** lalu tembak berurutan:

```bash
php artisan octane:start --server=frankenphp --workers=1 --max-requests=1000 &
# login sebagai user A, panggil endpoint, lalu login sebagai user B dan panggil endpoint yang sama
```

Satu worker memaksa kedua request dilayani proses yang sama — kalau ada kebocoran, di sinilah
ketahuan. Dengan banyak worker, bug yang sama muncul acak dan terlihat seperti "kadang error".

## Checklist saat menambah singleton

Sebelum menulis `singleton()`, jawab: **apa isinya saat request berikutnya datang dari user
berbeda?** Kalau jawabannya tidak pasti, jangan pakai singleton.

## Setelah mengubah kode

```bash
php artisan octane:reload      # muat kode baru tanpa memutus koneksi
```

Tanpa reload, worker lama masih menjalankan kode lama — dan ini sering disalahartikan sebagai
"perubahan saya tidak jalan".
