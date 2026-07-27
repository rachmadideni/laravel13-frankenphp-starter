---
name: vue-pinia-realtime
description: "Pola frontend Vue 3 + Vuetify 3 + Pinia + Vue Router untuk SPA yang menerima event realtime lewat Laravel Echo. Gunakan saat membuat store Pinia, plugin Echo, komponen Vuetify, navigation guard, konfigurasi Vite (outDir, proxy, VITE_*), atau saat mendiagnosis UI tidak ter-update walau event sampai, listener ganda, memory leak saat pindah halaman, dan build SPA yang tidak ditemukan Laravel."
---

# Vue + Pinia + Echo

## Listener terpusat di store, bukan tersebar di komponen

```ts
// src/stores/item.ts
export const useItemStore = defineStore('item', {
  state: () => ({ items: {} as Record<number, Item>, subscribed: false }),

  actions: {
    subscribe(plantId: number) {
      if (this.subscribed) return                 // cegah listener ganda
      this.subscribed = true
      window.Echo.private(`plant.${plantId}`)
        .listen('.item.updated', (e: Item) => { this.items[e.id] = e })
    },

    unsubscribe(plantId: number) {
      window.Echo.leave(`plant.${plantId}`)
      this.subscribed = false
    },
  },
})
```

Alasan listener tinggal di store, bukan di `onMounted` komponen:

- Komponen yang di-mount dua kali (tab, keep-alive, route berulang) mendaftarkan listener dua kali,
  dan setiap event lalu diproses berkali-kali.
- Beberapa komponen yang membutuhkan data sama berakhir dengan langganan terpisah ke channel sama.
- Saat store yang memegang state, seluruh komponen Vuetify yang membacanya ikut reaktif tanpa
  refetch manual dan tanpa polling.

Selalu sediakan `unsubscribe` dan panggil saat konteksnya berubah (ganti plant, logout).
Langganan yang tidak pernah dilepas adalah memory leak sekaligus kebocoran data setelah logout.

## Payload masuk apa adanya

Karena `broadcastWith()` memakai API Resource yang sama dengan REST, listener cukup meng-commit:

```ts
.listen('.item.updated', (e: Item) => { this.items[e.id] = e })
```

Kalau muncul kebutuhan mentransformasi payload di listener, penyebabnya ada di backend
(bentuk broadcast menyimpang dari API Resource) — perbaiki di sana, jangan tambal di frontend.

## Nama event

`broadcastAs()` di Laravel harus dipanggil dengan **titik di depan** pada Echo:
`.listen('.item.updated', …)`. Tanpa titik, event tidak pernah sampai dan tidak ada pesan error.

## Vite

```ts
export default defineConfig({
  build: { outDir: '../public/build', manifest: true, emptyOutDir: true },
  server: {
    proxy: {
      '/api':          { target: 'https://app.localhost', changeOrigin: false, secure: false },
      '/sanctum':      { target: 'https://app.localhost', changeOrigin: false, secure: false },
      '/broadcasting': { target: 'https://app.localhost', changeOrigin: false, secure: false },
    },
  },
})
```

- `changeOrigin: false` disengaja — mengubah origin membuat cookie Sanctum tidak cocok.
- Ketiga path harus di-proxy. Melewatkan `/broadcasting` membuat login berhasil tapi channel privat
  gagal diotorisasi, dan itu terlihat seperti "Reverb-nya rusak".

## Variabel `VITE_*`

Di-*bake* saat build, bukan dibaca saat runtime. Mengganti host Reverb berarti **rebuild image**.
Kalau harus bisa diganti tanpa rebuild, sajikan konfigurasi lewat endpoint `/api/config` dan baca
saat boot aplikasi.

Tidak satu pun `VITE_*` boleh berisi secret — semuanya terbaca di bundle browser.

## Router

Vue Router memakai history mode, jadi Laravel wajib punya route fallback ke SPA. Tanpa itu, refresh
di URL dalam menghasilkan 404 — jalan saat navigasi klien, rusak saat halaman dimuat ulang.

Navigation guard membaca store auth, bukan memanggil API di setiap perpindahan route.
