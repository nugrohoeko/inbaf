# INBAF

Platform asesmen Bahasa Indonesia untuk penutur asing. Implementasi lokal menggunakan **Laravel 13, PHP 8.3+, MySQL, Vue 3, Tailwind CSS, Lucide, dan Chart.js**.

## Menjalankan instalasi ini

Database `inbaf` telah dikonfigurasi melalui `.env`. Akun lokal unik tercatat di **`.local-access.txt`** (diabaikan Git). Tidak ada sandi produksi bersama di source code.

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Buka http://127.0.0.1:8000 . Aset frontend sudah dapat disajikan setelah `npm run build`; `npm run dev` digunakan saat mengembangkan antarmuka.

Terminal tambahan untuk pekerjaan otomatis:

```powershell
php artisan queue:work --tries=3
php artisan schedule:work
```

## Instalasi baru

1. Pasang PHP 8.3+ beserta PDO MySQL/SQLite, mbstring, intl, XML/DOM, fileinfo, GD, ZIP, OpenSSL; Composer; Node.js 22+; MySQL 8+ atau PostgreSQL.
2. Jalankan `composer install` dan `npm ci`.
3. Salin `.env.example` ke `.env`, atur koneksi database dan `APP_URL`, lalu `php artisan key:generate`.
4. Tentukan `INBAF_ADMIN_EMAIL` dan `INBAF_ADMIN_PASSWORD` yang kuat di `.env` sebelum menjalankan seeder. Akun peserta contoh hanya untuk lingkungan lokal/pengujian.
5. Jalankan `php artisan migrate --force`, `php artisan db:seed --force`, dan `npm run build`.
6. Arahkan document root web server ke folder **public/**. File rekaman tetap berada pada disk privat; jangan menyalinnya ke public.

## Alur penggunaan

**Superadmin:** kelola kompetensi dan level → unggah media/buat stimulus → susun soal dengan editor sesuai jenis → susun sesi paket, durasi, bobot, dan jadwal → publikasikan. Halaman penilaian menghubungkan respons esai/rekaman, skor rubrik, umpan balik, dan audit. Sertifikat dapat dicabut dengan alasan tercatat.

**Peserta:** daftar/masuk → isi profil → pilih paket → baca ketentuan → mulai ujian. Jawaban disimpan otomatis dan buffer lokal membantu sinkronisasi setelah koneksi kembali. Batas waktu tetap mengikuti server. Hasil objektif diproses otomatis; hasil akhir dan sertifikat menunggu penilaian manual yang dibutuhkan.

Tersedia tiga paket materi awal, tujuh kompetensi, 18 jenis soal, serta enam level internal. Materi awal harus ditelaah institusi sebelum dipakai untuk asesmen berkonsekuensi. Contoh listening tetap draft sampai rekaman Bahasa Indonesia diunggah; tidak ada tautan audio palsu atau statistik peserta buatan.

## Pemeriksaan

```powershell
php artisan test
npm run build
php artisan inbaf:backup
php artisan inbaf:expire-attempts
```

Pengujian PHP menggunakan SQLite in-memory; data MySQL lokal tidak dihapus oleh pengujian. Dokumen deployment menjelaskan backup, restore, cron, queue worker, HTTPS, dan batas verifikasi. Antarmuka browser perlu diuji pada mikrofon/perangkat yang akan digunakan peserta.

Pengujian browser menggunakan database SQLite khusus `tmp/browser.sqlite`, yang direset setiap suite. Jalankan server terpisah pada terminal baru:

```powershell
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=Join-Path (Get-Location) 'tmp/browser.sqlite'
$env:DB_URL=''
php artisan serve --host=127.0.0.1 --port=8001
```

Kemudian jalankan `npm run test:browser` dari terminal lain. Suite memuat **37 tes dalam 8 file**, memakai Chrome yang sudah terpasang dan kredensial lokal di `.env`, serta menolak config cache yang dapat mengarahkan tes ke database utama. Cakupannya meliputi kebijakan tiga jenis tes, kerahasiaan soal, laporan penempatan, navbar sticky, preferensi ukuran teks, pause/resume listening, label pembahasan, pergantian filter dengan respons terlambat, resize grafik saat navigasi cepat, profil/foto/password/logout Superadmin dan peserta, preferensi bahasa akun, nama historis sertifikat pada kedua role, landing interaktif beserta alur login sesuai jenis tes, serta alur ujian lama. Tes speaking memakai mikrofon simulasi untuk rekam, upload, recovery, media privat dan penilaian manual; mikrofon fisik tetap perlu diuji pada perangkat peserta. Bukti dan rincian run tersedia pada [hasil verifikasi lokal](docs/verification.md). Gunakan `npm run test:browser -- --list` untuk memeriksa daftar tanpa menjalankan tes; laporan default `tmp/playwright-results/report.json` mengikuti perintah terakhir. `INBAF_PHP_PATH` dan `INBAF_CHROME_PATH` dapat mengganti executable. Hentikan server uji setelah selesai; aplikasi utama tetap menggunakan port 8000 dan MySQL `.env`.

Jika execution policy PowerShell memblokir `npm.ps1`, gunakan `npm.cmd run test:browser` dan `npm.cmd run build`.

## Dokumentasi

- [Tiga jenis tes, kebijakan, aksesibilitas, dan diagram](docs/test-types.md)
- [Arsitektur, ERD, alur, dan RBAC](docs/architecture.md)
- [Deployment dan operasional](docs/deployment.md)
- [Cakupan persyaratan dan batas implementasi](docs/requirements.md)
- [Kontrak API](docs/api.md)
- [Panduan pengguna dan Superadmin](docs/user-guide.md)
- [Profil, foto dan keamanan sesi Superadmin](docs/superadmin-profile.md)
- [Profil, foto, bahasa dan keamanan akun peserta](docs/participant-profile.md)
- [Landing page, navigasi dan atlas bahasa interaktif](docs/landing-page.md)
- [Sumber media](docs/media-attribution.md)
- [Aset dan pedoman identitas](docs/branding.md)
- [Hasil verifikasi lokal](docs/verification.md)

Pemetaan A1–C2 adalah klasifikasi internal INBAF dan membutuhkan validasi empiris. Platform tidak mengklaim pengakuan resmi atau kesetaraan TOEFL/UKBI. Integrasi AI dan email eksternal memerlukan penyedia yang dikonfigurasi dan diuji; fitur tersebut tidak diaktifkan semu.

Bank soal mendukung impor CSV UTF-8 dan Excel `.xlsx`, maksimal 500 baris per unggahan, dengan validasi menyeluruh sebelum transaksi penyimpanan. Template serta ekspor Excel tersedia melalui parameter `?format=xlsx` pada endpoint template/ekspor.
