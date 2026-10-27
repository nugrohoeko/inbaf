# Preferensi ukuran teks

Pilih **Kecil (14px)**, **Normal (16px)**, **Besar (18px)**, atau **Sangat besar (20px)** melalui kontrol ukuran teks pada header ruang peserta/admin atau ujian. Teks mengikuti pilihan ini tanpa memerlukan reload.

Untuk pengunjung, pilihan disimpan pada perangkat. Untuk pengguna yang masuk, pilihan juga disimpan pada akun dan dipulihkan oleh bootstrap setelah login atau reload. Permintaan penyimpanan dikirim berurutan agar perubahan cepat tetap menghasilkan pilihan terakhir. Jika penyimpanan akun gagal, ukuran tetap diterapkan pada perangkat dan pesan kegagalan ditampilkan.

## API dan database

`PUT /api/preferences` memerlukan sesi pengguna aktif dan CSRF token. Body JSON:

```json
{"text_size":"extra_large"}
```

Nilai yang diterima adalah `small`, `normal`, `large`, dan `extra_large`. Respons memuat `user.text_size`. Input tidak valid menghasilkan 422; pengguna hanya dapat mengubah preferensi akunnya sendiri.

Migrasi `2026_10_23_000400_add_text_size_to_users` menambahkan kolom `users.text_size` dengan default `normal`. Terapkan migrasi saat deployment sebelum memakai endpoint ini.

## Verifikasi

```powershell
php artisan test --filter=UserPreferencesTest
npm.cmd run test:frontend
npm.cmd run build
npm.cmd run test:browser -- preferences.spec.js
```

Ikuti README untuk server dan database browser yang terpisah. Tes browser mencakup perubahan ukuran teks nyata, recovery pengunjung, pemulihan dari akun, perubahan cepat, bank soal admin, dan navigasi ujian pada lebar 1440, 390, dan 320 piksel.

Bukti QA lokal terpisah: `tmp/qa/text-size-report.json` serta tangkapan layar `tmp/qa/text-size-*-320.png`. Run QA ini memakai server port 8002 dan SQLite `tmp/qa/text-size.sqlite` melalui konfigurasi sementara di direktori QA.

Pada 23 Oktober 2026, empat tes Chrome preferensi lolos (sekitar 1,3 menit), tiga tes regresi JavaScript lolos, dan suite PHP saat pemeriksaan berisi 82 tes/441 assertion yang seluruhnya lolos. Build QA menggunakan direktori aset tersendiri agar build dari sesi lain tidak menghapus manifest di tengah pengujian. Metadata build disimpan di `tmp/qa/text-size-build-info.json`; server dan aset QA sementara dibersihkan setelah pengujian.
