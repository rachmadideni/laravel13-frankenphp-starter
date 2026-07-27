---
name: sanctum-spa-auth
description: "Autentikasi SPA dengan Laravel Sanctum mode cookie/stateful dan otorisasi channel broadcasting. Gunakan saat menulis atau memperbaiki endpoint login/logout/me, konfigurasi SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN, CSRF, axios withCredentials, route fallback SPA, routes/channels.php, endpoint /broadcasting/auth, dan saat mendiagnosis 419 CSRF token mismatch, 401 padahal sudah login, cookie tidak terkirim, atau godaan menambah CORS untuk 'memperbaiki' masalah auth."
---

# Sanctum SPA (Cookie Mode) + Otorisasi Channel

Mode yang dipakai: **cookie/stateful**, bukan bearer token. SPA di-serve dari origin yang sama
dengan API, sehingga tidak ada CORS dan token tidak pernah disimpan di JavaScript.

## Aturan

- Jangan menambah endpoint penerbit bearer token. Kalau nanti ada aplikasi mobile, itu keputusan
  arsitektur tersendiri — bukan tambalan.
- **Jangan menambah konfigurasi CORS lebar untuk menyelesaikan 401/419.** Hampir selalu penyebabnya
  proxy Vite atau `SANCTUM_STATEFUL_DOMAINS` yang salah, dan CORS lebar hanya menyembunyikannya
  sambil memperluas permukaan serangan.
- Secret tidak pernah masuk bundle frontend. Hanya `VITE_*` yang terbaca browser, dan tidak satu pun
  boleh berisi secret.

## Konfigurasi

```dotenv
SESSION_DRIVER=redis
SESSION_DOMAIN=.localhost
SANCTUM_STATEFUL_DOMAINS=localhost:5173,app.localhost
```

`localhost` dan `127.0.0.1` adalah dua host berbeda untuk cookie. Akses SPA lewat host yang sama
persis dengan yang terdaftar di `SANCTUM_STATEFUL_DOMAINS`.

## Alur klien

```ts
const api = axios.create({ baseURL: '/', withCredentials: true })

await api.get('/sanctum/csrf-cookie')      // wajib sebelum login
await api.post('/login', { email, password })
const me = await api.get('/api/me')
```

Melewatkan `/sanctum/csrf-cookie` menghasilkan **419**, bukan 401 — itu petunjuk paling cepat
membedakan masalah CSRF dari masalah kredensial.

## Diagnosis cepat

| Gejala | Penyebab paling umum |
|---|---|
| 419 CSRF token mismatch | `/sanctum/csrf-cookie` tidak dipanggil, atau `SESSION_DOMAIN` tidak mencakup host |
| 401 padahal sudah login | host tidak terdaftar di `SANCTUM_STATEFUL_DOMAINS`, atau `withCredentials` lupa |
| Cookie tidak terkirim | akses lewat `127.0.0.1` sementara yang terdaftar `localhost` |
| Refresh di URL dalam → 404 | route fallback SPA belum ada |

## Otorisasi channel — jalur terpisah yang sering terlupa

Route API yang aman **tidak** menutup lubang WebSocket. Keduanya jalur berbeda, dan channel privat
yang hanya mencocokkan id user tetap membocorkan data ke pengguna tanpa hak.

```php
// ❌ hanya identitas
Broadcast::channel('plant.{id}', fn ($user, $id) => $user->plant_id === (int) $id);

// ✅ identitas + permission
Broadcast::channel('plant.{id}', fn ($user, $id) =>
    $user->can('dashboard.view') && $user->plant_id === (int) $id);
```

Setiap closure di `routes/channels.php` wajib memanggil `can()` atau Policy.

## Test yang wajib ada

```php
it('menolak subscribe channel privat tanpa permission', function () {
    $user = User::factory()->create();                       // tanpa permission
    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id'    => '123.456',
            'channel_name' => 'private-plant.1',
        ])
        ->assertForbidden();
});
```

Test **negatif** ini lebih penting daripada test positifnya. Yang positif hampir selalu ditulis;
yang negatif adalah yang menangkap kebocoran.
