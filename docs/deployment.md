# Deployment dan operasi

## Prasyarat

- PHP 8.3 atau lebih baru yang kompatibel dengan lock Composer; lingkungan dan pengujian aplikasi ini menggunakan PHP 8.3.31.
- Ekstensi PHP: PDO beserta driver MySQL/PostgreSQL, mbstring, OpenSSL, tokenizer, XML/DOM, fileinfo, ctype, session, GD dan zip sesuai dependensi. GD diperlukan untuk PNG QR sertifikat.
- MySQL 8 atau PostgreSQL yang didukung Laravel, Composer 2, Node.js LTS dan npm.
- Web server mengarah **hanya** ke direktori `public/`.
- HTTPS, penyimpanan persisten untuk media, worker queue dan scheduler.

Atur batas memori PHP sesuai aset PDF yang digunakan; 256 MB atau lebih diperlukan untuk gambar resolusi tinggi, dan uji penggunaan memori dengan materi institusi. Asset gambar sertifikat dibatasi 10 MB serta 24 megapiksel; file tetap harus disertakan dalam backup agar hash historis cocok.

Pengujian otomatis menggunakan SQLite terisolasi. Validasi transaksi dan konkurensi pada MySQL/PostgreSQL target tetap menjadi bagian uji deployment. Jangan mengasumsikan perilaku lock SQLite sama dengan database produksi.

## Instalasi

Jalankan dari direktori aplikasi. Buat database dan pengguna aplikasi dengan akses hanya ke database tersebut melalui sistem administrasi database setempat.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Isi `.env` dengan nilai deployment yang benar:

```dotenv
APP_NAME=INBAF
APP_ENV=production
APP_DEBUG=false
APP_URL=https://assessment.example.org
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inbaf
DB_USERNAME=inbaf_app
DB_PASSWORD=CHANGE_VIA_SECRET_MANAGER
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Nilai contoh domain dan password wajib diganti. `APP_URL` menentukan tujuan QR. Simpan `APP_KEY` sebagai secret yang persisten dan sertakan dalam backup terenkripsi. Jangan menjalankan `key:generate` ulang pada aplikasi yang sudah memiliki sertifikat.

```sh
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Seeder menyediakan materi contoh untuk demonstrasi. Gunakan prosedur akun admin yang dijelaskan pada README dan ganti kredensial awal. Jangan memuat demo ke database institusi yang sudah berjalan tanpa meninjau isinya. Tidak ada kredensial produksi yang layak dibagikan secara publik.

Pastikan pengguna web server dapat menulis `storage/` dan `bootstrap/cache/`. Jangan membuat seluruh proyek writable atau mengekspos `storage/app/private`. Jangan memakai `storage:link` untuk membuat rekaman peserta dapat diakses publik.

## Queue dan scheduler

Jalankan worker sebagai service dengan Supervisor, systemd atau pengelola proses setempat:

```sh
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

Jalankan scheduler setiap menit (contoh cron Linux):

```cron
* * * * * cd /srv/inbaf && php artisan schedule:run >> /var/log/inbaf-scheduler.log 2>&1
```

Pada Windows gunakan Task Scheduler untuk menjalankan `php artisan schedule:run` setiap menit dengan working directory proyek. Periksa daftar tugas dengan `php artisan schedule:list`. Auto-submit juga diperiksa ketika peserta mengakses attempt; scheduler diperlukan agar attempt ditutup saat browser sudah tidak aktif.

Tugas `inbaf:expire-attempts` dijadwalkan setiap menit dengan `withoutOverlapping` dan mengirim `FinalizeExpiredAttempt` ke queue. Worker harus aktif jika `QUEUE_CONNECTION=database`. Jalankan manual untuk diagnosis:

```sh
php artisan inbaf:expire-attempts
php artisan queue:failed
```

Submit peserta, penilaian manual dan pembuatan PDF berlangsung sinkron. Ukur beban sebelum memindahkan pekerjaan lain ke proses asinkron dan pertahankan idempotensi.

## Backup dan restore

Backup harus mencakup database, media privat, `.env`/secret terutama `APP_KEY`, dan versi rilis (`composer.lock`, `package-lock.json`, source). Enkripsi backup dan batasi hak aksesnya. Tentukan retensi berdasarkan kebijakan institusi dan persetujuan peserta. Aplikasi menyediakan perintah backup database privat:

```sh
php artisan inbaf:backup
```

Perintah menggunakan `mysqldump`/`pg_dump` pada MySQL/PostgreSQL dan `VACUUM INTO` pada SQLite. Executable database perlu tersedia di PATH proses. Hasil berada di disk `local` pada direktori `backups`; perintah tidak otomatis mengenkripsi atau menyalin media. Jadwalkan dan salin hasil ke penyimpanan terpisah. Alternatif MySQL menggunakan berkas kredensial aman, bukan password pada argumen CLI:

```sh
mysqldump --defaults-extra-file=/secure/inbaf-backup.cnf --single-transaction --routines --triggers inbaf > /secure/backups/inbaf.sql
```

Salin direktori media privat ke penyimpanan backup terenkripsi dengan snapshot yang konsisten. Untuk restore, gunakan lingkungan terpisah terlebih dahulu:

```sh
mysql --defaults-extra-file=/secure/inbaf-restore.cnf inbaf_restore < /secure/backups/inbaf.sql
```

Pulihkan media dan `APP_KEY` yang sesuai; arahkan konfigurasi ke database restore. Jalankan `php artisan migrate:status`, uji login, buka rekaman sampel, unduh PDF dan verifikasi token sertifikat yang sudah terbit. Dokumentasikan waktu pemulihan nyata. Perintah backup di atas merupakan panduan; penjadwalan, enkripsi dan uji restore harus dikonfigurasi operator.

## Pemeriksaan sebelum publikasi

- `composer audit` dan audit npm dievaluasi; patch diterapkan tanpa menonaktifkan kontrol keamanan.
- Jalankan `php artisan test` dan `npm run build`; lakukan smoke test di database target dan dua browser.
- Uji peserta terpisah tidak dapat mengakses attempt, media, sertifikat atau endpoint admin pengguna lain.
- Uji mikrofon pada HTTPS, penolakan izin, upload gagal, koneksi putus, reload dan pemulihan autosave.
- Uji waktu sesi, periode ujian, batas kesempatan, submit bersamaan dan penyelesaian scheduler.
- Uji durasi per soal: aktivasi pertama, revisit/reload, save tepat di batas, antrean offline kedaluwarsa dan waktu upload speaking.
- Uji esai/speaking menunggu penilaian, hasil berbobot dan sertifikat tidak terbit terlalu awal.
- Scan QR dari PDF cetak; periksa `APP_URL`, template, token valid, revoked, expired dan record yang dimodifikasi.
- Uji responsive, keyboard, screen reader, kontras dan reduced motion. WCAG 2.2 AA adalah sasaran, bukan sertifikasi hasil audit otomatis.
- Pastikan HTTPS dan cookie secure, `APP_DEBUG=false`, `.env` tidak dapat diunduh, log tidak mengekspos kunci jawaban atau data sensitif.
- Konfigurasikan pemantauan error, kapasitas disk, job gagal, scheduler heartbeat dan alert.
- Tetapkan kebijakan privasi, retensi rekaman, penghapusan akun, verifikasi identitas dan tanggung jawab reviewer.
- Lakukan load test dan uji restore; tentukan kapasitas peserta bersamaan berdasarkan hasil pengukuran.

Kesiapan operasional produksi memerlukan hasil pemeriksaan ini pada infrastruktur institusi. Implementasi lokal bukan pengganti audit keamanan, akreditasi penilaian, audit aksesibilitas atau uji kapasitas produksi.
