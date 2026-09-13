# Transfer API

Jalankan dari direktori `payment-api`:

```sh
php artisan migrate
php artisan queue:work transfers --queue=transfers --sleep=1 --tries=3 --timeout=30
```

Koneksi queue `transfers` memakai tabel `jobs` pada database aplikasi. Record transfer dan job disimpan dalam satu commit. Worker harus berjalan di proses terminal terpisah dari `php artisan serve`.

Kirim `POST /transfer` (alias `/api/transfer`) dengan Bearer access token:

```json
{
  "target_user": "b7342e8e-e8e7-4a5d-873e-b1b1bfcdeddb",
  "amount": 30000,
  "remarks": "Hadiah Ultah"
}
```

`target_user` mengacu ke UUID `users.user_id`. Amount minimal 1, maksimal 9999999999999.99, dengan maksimal dua angka desimal. Remarks wajib string maksimal 255 karakter. Akun sendiri tidak boleh menjadi penerima.

API menunggu maksimal 5 detik (diatur di `config/transfers.php`). Jika worker selesai, HTTP 200 berisi:

```json
{
  "status": "SUCCESS",
  "result": {
    "transfer_id": "uuid-transfer",
    "amount": 30000,
    "remarks": "Hadiah Ultah",
    "balance_before": 400000,
    "balance_after": 370000,
    "created_date": "2026-09-12 12:00:00"
  }
}
```

Jika masih antre, HTTP 202 berisi `{"status":"PENDING","result":{"transfer_id":"uuid-transfer"}}`. Header `Location` menunjuk ke `/transfer/{transfer_id}`. Gunakan GET ke URL tersebut dengan token pengirim sampai status final. Jangan mengulang POST hanya karena mendapat PENDING: POST baru membuat transfer baru. GET status juga tersedia di `/api/transfer/{transfer_id}` dan hanya dapat diakses pengirim.

Saldo kurang menghasilkan HTTP 400 dengan `{"message":"Balance is not enough"}`. Tanpa autentikasi menghasilkan HTTP 401 dengan `{"message":"Unauthenticated"}`. Request tidak valid menghasilkan HTTP 422 bila memakai header `Accept: application/json`.

Saldo diperiksa ulang oleh worker di bawah lock; transfer lain atau Payment yang lebih dahulu selesai dapat menyebabkan transfer pending gagal karena saldo tidak cukup. Job mengunci transfer dan kedua user, memperbarui saldo serta mencatat `transfer_out` dan `transfer_in` dalam satu transaksi. Job yang dikirim ulang melewati transfer yang sudah final.

Job mencoba sampai tiga kali untuk kegagalan teknis. Jika seluruh percobaan gagal, status transfer menjadi `failed`; saldo tetap utuh karena rollback. Status dapat diperiksa melalui GET. `queue:retry` untuk transfer yang sudah berstatus failed tidak memindahkan saldo lagi.

Pengujian:

```sh
php artisan test --filter="TransferTest|PaymentTest"
```

Tes menggunakan SQLite in-memory dan worker database queue; penguncian konkuren MySQL belum diuji langsung. Tes menjalankan migration yang relevan secara selektif karena migration lama `add_fields_to_transactions_table` mengulangi kolom pada migration awal transactions.
