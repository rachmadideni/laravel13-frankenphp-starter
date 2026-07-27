---
name: queue-realtime-contract
description: "Merancang job Horizon dan event broadcast Reverb beserta kontrak payload lintas-lapis. Gunakan saat membuat Job, Event, API Resource, konfigurasi supervisor Horizon, penamaan queue, broadcastWith/broadcastAs, atau saat mendiagnosis event yang tidak sampai ke browser, payload broadcast berbeda bentuk dengan REST, job yang menumpuk, dan queue yang saling menunda."
---

# Kontrak Queue → Broadcast → Store

## Aturan tunggal yang menahan seluruh rantai

**API Resource = bentuk state Pinia = payload broadcast.** Satu bentuk data saja.

```php
class ItemResource extends JsonResource { /* ... */ }

class ItemUpdated implements ShouldBroadcast
{
    public function __construct(public Item $item) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("plant.{$this->item->plant_id}");
    }

    public function broadcastAs(): string
    {
        return 'item.updated';
    }

    public function broadcastWith(): array
    {
        return (new ItemResource($this->item))->resolve();   // ← pakai ulang, jangan tulis ulang
    }
}
```

Kalau muncul dua bentuk berbeda untuk entitas yang sama, itu **bug, bukan gaya**: listener di store
terpaksa melakukan transformasi khusus, dan perbedaan itu akan menyimpang diam-diam.

## Nama event di klien

`broadcastAs()` mengubah nama event menjadi custom, dan di sisi Echo harus diawali titik:

```ts
.listen('.item.updated', (e) => { ... })    // titik di depan wajib untuk broadcastAs
```

Lupa titik = event tidak pernah sampai, tanpa pesan error. Ini penyebab "realtime saya tidak jalan"
yang paling sering.

## Broadcast dari dalam worker

Worker antrean adalah proses **berbeda** dari server web. Broadcast dari job tetap harus melewati
server Reverb — Laravel menanganinya otomatis, asalkan konfigurasinya benar:

- `REVERB_HOST` / `REVERB_PORT` → alamat **internal** (nama container), dipakai server & worker.
- `VITE_REVERB_HOST` / `VITE_REVERB_PORT` → alamat **publik**, dipakai browser.

Menyamakan keduanya adalah kesalahan klasik: browser mencoba menghubungi nama container, atau
worker mencoba menghubungi domain publik dan gagal saat DNS internal berbeda.

## Pemisahan queue

Minimal dua queue dengan supervisor terpisah, supaya lonjakan di satu jenis pekerjaan tidak
menunda yang lain:

```php
ProcessImport::dispatch($file)->onQueue('default');
SendCriticalAlert::dispatch($x)->onQueue('priority');
```

Satu queue untuk semuanya berarti satu impor besar bisa menahan pekerjaan yang sensitif waktu.

## Desain job

- Job harus **idempoten**: retry pasti terjadi (timeout, deploy, worker mati).
- Kirim **id**, bukan objek Eloquent utuh, sebagai constructor payload. Objek yang di-serialize bisa
  basi saat job akhirnya berjalan.
- `$tries` dan `$backoff` ditetapkan eksplisit; jangan bergantung pada default.
- Job panjang wajib melapor kemajuan lewat broadcast, bukan membiarkan UI menebak.

## Test yang membuktikan rantainya nyata

```php
it('menyiarkan payload yang sama dengan API Resource', function () {
    Event::fake([ItemUpdated::class]);
    ProcessItem::dispatch($item->id);        // jalankan job sungguhan, bukan Queue::fake saja
    Event::assertDispatched(ItemUpdated::class, fn ($e) =>
        $e->broadcastWith() === (new ItemResource($item->fresh()))->resolve());
});
```

`Queue::fake()` membuktikan job *di-dispatch*, bukan bahwa rantainya *bekerja*. Minimal satu test
harus menjalankan job sungguhan sampai event tersiar.
