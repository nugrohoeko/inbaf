# Prosedur rilis, pemulihan, dan smoke test INBAF

Dokumen ini adalah prosedur operator. Audit final melakukan pengujian lokal dan pada salinan database; deployment production belum dijalankan. Status kelulusan ada di [final-qa-report.md](final-qa-report.md).

## 1. Bekukan dan identifikasi rilis

1. Simpan source yang telah diuji sebagai commit/tag rilis yang dapat ditinjau. Catat commit, `composer.lock`, `package-lock.json`, versi runtime, serta hasil QA bersama artefak rilis.
2. Gunakan PHP yang sudah menerima patch keamanan, Node yang sudah ditambal untuk build, dan database yang masih dipelihara. Verifikasi binary PHP web server dan CLI secara terpisah.
3. Bangun pada direktori rilis baru dengan `composer install --no-dev --prefer-dist --optimize-autoloader`, `npm ci`, dan `npm run build`.
4. Isi secret production melalui pengelolaan secret operator. Pertahankan **APP_KEY asli** karena kunci tersebut menandatangani sertifikat historis. Gunakan domain HTTPS final untuk URL verifikasi QR.
5. Artefak publik berisi direktori `public` beserta hasil build. Pisahkan `.env`, `.git`, `tmp`, test artifacts, log, backup, dan media privat dari document root. Marker Vite `public/hot` hanya untuk pengembangan dan dikecualikan dari artefak rilis.

## 2. Backup sebelum perubahan

Tetapkan waktu pemeliharaan berdasarkan attempt aktif. Timer mengikuti waktu server; downtime saat ujian membutuhkan prosedur institusi yang disepakati terlebih dahulu.

- Buat backup database privat memakai `php artisan inbaf:backup`. Verifikasi exit code, ukuran, checksum dan waktu pembuatan.
- Snapshot seluruh media privat, termasuk rekaman, foto profil, serta gambar logo/background/signature sertifikat. Sertakan berkas yang dirujuk riwayat, bukan hanya media yang sedang aktif.
- Simpan APP_KEY dan konfigurasi rahasia dalam backup terenkripsi terpisah dengan akses operator terbatas. Perintah database backup tidak mengarsipkan secret atau media dan tidak mengenkripsi hasilnya secara otomatis.
- Salin backup ke lokasi terpisah, tetapkan retensi, dan uji pembacaan hasil salinannya. Catat RPO/RTO yang disetujui operator.
- Restore dahulu ke database kosong terisolasi dan salinan direktori media. Cocokkan jumlah/hash data, jalankan audit integritas, buka media privat sampel dan PDF sertifikat historis, kemudian verifikasi tokennya memakai APP_KEY yang sesuai. Simpan bukti waktunya.

Audit lokal telah membuktikan dump/restore MySQL dan checksum dua aset terdaftar. Backup seluruh secret/media, enkripsi, offsite, dan restore pada hosting target tetap memerlukan bukti operator.

## 3. Validasi salinan dan konfigurasi target

Jalankan pada staging atau salinan yang dituju, dengan kredensial yang terbatas ke database tersebut:

```sh
php artisan migrate:status
php artisan inbaf:audit-data --json
php artisan inbaf:readiness --json
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan schedule:list
php artisan queue:failed
```

Periksa rencana migration sebelum menjalankannya. Patch audit final ini tidak menambah migration. Terapkan migration mendatang setelah backup dan uji pada salinan; perubahan destruktif memerlukan rencana serta otorisasi tersendiri.

`inbaf:audit-data` hanya membaca dan mengeluarkan agregat. Temuan data perlu diselidiki berdasarkan relasi serta riwayatnya; perintah tersebut tidak memperbaiki atau menghapus record. `inbaf:readiness` adalah preflight konfigurasi dan tetap memerlukan bukti eksternal untuk TLS, email, kapasitas, backup dan operasi hosting.

Pada Windows, override `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` dan `APP_EVENTS_CACHE` untuk uji isolasi memakai path relatif terhadap proyek. Prosedur yang telah diuji tersedia dalam `tests/Operations/cache-smoke.php`; cache utama pengembang tetap terpisah.

## 4. Aktivasi yang diawasi operator

1. Pastikan document root menuju `public`, rewrite aktif, HTTPS valid, `APP_ENV=production`, `APP_DEBUG=false`, cookie secure, serta akses `.env`/file privat ditolak oleh web server.
2. Gunakan direktori media dan secret yang persisten lintas versi rilis. Verifikasi izin tulis `storage` dan `bootstrap/cache` sebagai pengguna proses web.
3. Validasi email aktivasi/reset sampai pesan diterima pada mailbox uji. Pastikan token bekerja satu kali dan kedaluwarsa sesuai konfigurasi. Pemeriksaan konfigurasi SMTP saja belum membuktikan pengiriman.
4. Alihkan rilis melalui mekanisme hosting yang dapat dibalik. Reload PHP/OPcache sesuai hosting dan restart worker untuk memuat kode baru.
5. Jalankan worker dan scheduler sebagai service yang dipantau. Pastikan `retry_after` lebih besar daripada timeout job/worker yang efektif. Contoh worker untuk job expiry INBAF:

```sh
php artisan queue:work --sleep=3 --tries=3 --timeout=60
php artisan queue:restart
```

Scheduler menjalankan `php artisan schedule:run` setiap menit dengan working directory dan environment rilis yang benar. Buktikan attempt yang berakhir ketika browser ditutup diproses oleh scheduler dan worker tersebut.

## 5. Smoke test setelah pengalihan

| Pemeriksaan | Bukti yang dicatat operator |
| --- | --- |
| Health dan aset | `/up`, login, logo, CSS/JS hasil build berhasil melalui domain HTTPS asli |
| Kedua role | Login/logout; peserta ditolak dari API admin; pergantian password mencabut sesi lain |
| Pengelolaan akun | Akun uji, perubahan status, aktivasi/reset dan email yang benar-benar diterima |
| Profil dan media | Upload/crop foto, stream audio privat, penolakan akses peserta lain |
| Tiga jenis tes | Start, autosave, refresh/recovery, submit dan hasil; practice/placement tidak menerbitkan sertifikat sertifikasi |
| Penilaian | Pending manual dibedakan dari final; rubrik snapshot menghasilkan skor yang sama di hasil dan laporan |
| Sertifikat | PDF historis dapat dibuka; QR dipindai menuju domain final; valid/expired/revoked/not found benar |
| Queue/scheduler | Expiry tanpa browser, job gagal/retry dan monitoring service diperiksa |
| Observabilitas | Error rate, p95, query, disk, queue backlog dan alarm diuji pada kapasitas yang disepakati |

Gunakan data QA yang teridentifikasi dan prosedur institusi yang disetujui; pertahankan riwayat serta audit yang sudah dihasilkan.

## 6. Rollback

1. Gunakan kegagalan smoke test atau ambang error/latensi yang disepakati sebagai pemicu. Catat waktu, rilis, dan dampaknya; amankan log dengan membatasi identitas/token.
2. Hentikan penerimaan ujian baru melalui prosedur pemeliharaan yang sudah disepakati. Pertahankan media serta database terbaru untuk investigasi dan rekonsiliasi.
3. Alihkan aplikasi ke artefak rilis sebelumnya yang sudah disiapkan. Pertahankan APP_KEY, media dan database yang kompatibel. Bangun ulang cache rilis itu dan restart worker/PHP sesuai hosting.
4. Jalankan ulang smoke test login, akses hasil, media, PDF/QR historis dan queue. Pastikan versi kode yang aktif sesuai rilis rollback.
5. Jika schema tidak kompatibel, operator memilih pemulihan database hanya setelah menilai transaksi yang terjadi sejak backup dan menyetujui dampak kehilangan datanya. Restore ke database terpisah dahulu, validasi, rekonsiliasi media/record baru, lalu lakukan pengalihan yang disetujui. Rollback migration destruktif bukan langkah otomatis.
6. Simpan bukti pemulihan dan waktu aktual. Lakukan audit integritas serta pemeriksaan queue sebelum membuka ujian kembali.

Runbook tersedia dan dump/restore lokal telah diuji. Pengalihan rilis, rollback hosting, pemulihan bencana, pengiriman email dan kapasitas production belum dieksekusi dalam audit lokal ini.
