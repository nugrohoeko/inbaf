# Manajemen Pengguna INBAF

Implementasi dan verifikasi: 24 Oktober 2026.

## Penggunaan

Superadmin membuka **Manajemen Pengguna** pada sidebar atau `/admin/users`. Halaman ini mengelola akun Superadmin dan Peserta dalam tabel `users` yang sama dengan registrasi mandiri.

- Ringkasan jumlah pengguna, Superadmin, Peserta, aktif, nonaktif, dan menunggu aktivasi berasal dari database. Akun yang dihapus dapat ditampilkan melalui filter tersendiri.
- Daftar mendukung pencarian nama/email, filter role/status, sorting, pagination, serta keadaan loading, kosong, dan gagal memuat.
- Tambah/edit mencakup identitas, email unik, nomor telepon, role, status, foto, dan field profil Peserta yang tampil sesuai role. Sistem tidak menggunakan username.
- Detail menampilkan profil, jumlah riwayat ujian/sertifikat, dan aktivitas dengan pelaksana, waktu, tindakan, serta status yang aman ditampilkan.
- Tindakan mencakup edit, aktif/nonaktif, kirim ulang aktivasi, reset password melalui email, dan hapus dengan konfirmasi. Perubahan role memerlukan konfirmasi tersendiri.
- Foto divalidasi, diproses ulang sebagai gambar, dan disimpan pada storage privat. Foto pengguna lain hanya tersedia melalui endpoint administratif yang terlindungi.
- Tampilan memakai identitas emerald/lime INBAF, light/dark mode, modal yang dapat dioperasikan melalui keyboard, dan layout responsif. Tidak ada dropdown ukuran font baru.

## Dua metode pembuatan akun

**Password manual:** administrator menentukan password awal yang memenuhi aturan kekuatan dan konfirmasi. Password disimpan dengan hashing Laravel. Untuk kedua role, login pertama mewajibkan pengguna mengganti password sebelum mengakses fitur lain. Penggantian membutuhkan password saat ini dan password baru yang berbeda.

**Aktivasi email:** akun dengan status aktif yang dibuat melalui metode ini disimpan sebagai `pending_activation` dan belum dapat login. Pengguna membuka `/set-password` dari email, menentukan password pribadi, lalu login melalui halaman biasa. Aktivasi tidak melakukan login otomatis. Jika email belum dapat dikirim, akun tetap tersimpan sebagai pending dan UI menampilkan kegagalan pengiriman secara jelas; Superadmin dapat mengirim ulang setelah konfigurasi diperbaiki.

Akun yang sengaja dibuat nonaktif tetap tidak dapat login. Reset password tidak mengaktifkan kembali akun yang dinonaktifkan oleh administrator.

## Keamanan dan retensi

- Seluruh halaman dan endpoint administratif memeriksa autentikasi serta role Superadmin di backend. Peserta tidak dapat mengakses atau meningkatkan hak akses melalui payload profil/registrasi.
- Pembuatan user, profil, perubahan role/status, dan audit memakai transaksi. Operasi administratif mengunci kumpulan Superadmin secara konsisten dan memeriksa ulang pelaksana beserta versi sesinya.
- Akun sendiri tidak dapat dihapus, dinonaktifkan, atau diturunkan rolenya melalui manajemen pengguna. Superadmin aktif terakhir dilindungi. Password akun sendiri diubah melalui profil dengan verifikasi password saat ini.
- Perubahan keamanan menaikkan `session_version`, mengganti remember token, dan mencabut sesi terkait. Pemeriksaan versi juga menghentikan sesi pada driver selain database. Penggantian password/email pribadi mempertahankan sesi saat ini melalui snapshot dan cookie sesi yang diperbarui.
- Saat email berubah, token alamat lama dihapus sebelum alamat diperbarui. Pengiriman kredensial memuat ulang dan mengunci user terkini agar email/status dari objek lama tidak menghasilkan token yang salah.
- Token memakai password broker Laravel: acak, disimpan sebagai hash, terikat ke email, berlaku **60 menit**, dan hanya dapat dikonsumsi sekali. Pengiriman ulang dibatasi; penerbitan baru menggantikan token sebelumnya. Akun yang dihapus atau token tidak valid/kedaluwarsa ditolak.
- Password dan token rahasia tidak dikembalikan pada API atau dicatat dalam audit. Password plaintext tidak dikirim melalui email. Mailer `log`, `array` di luar pengujian, dan transport gabungan yang memuat mailer tersebut ditolak agar tautan aktif tidak masuk ke log.
- Penghapusan memakai **soft delete**. Profil, attempt, jawaban, penilaian, audit, dan sertifikat tetap tersimpan. Relasi historis tetap dapat membaca pengguna yang dihapus; payload dan tanda tangan sertifikat tidak diubah oleh pengelolaan akun. Status valid/kedaluwarsa/dicabut tetap mengikuti kebijakan sertifikat yang sudah ada.
- Endpoint peserta administratif lama memakai aturan penulisan/status yang sama agar tidak menjadi jalur untuk melewati perlindungan baru.

## Endpoint

Semua endpoint `/api/admin/users` memerlukan sesi Superadmin aktif dan CSRF untuk mutasi. Pembatasan permintaan diterapkan pada penulisan dan pengiriman kredensial.

| Metode | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api/admin/users` | Daftar terfilter, pagination, statistik |
| POST | `/api/admin/users` | Buat akun dan profil terkait |
| GET | `/api/admin/users/{id}` | Detail dan riwayat aktivitas |
| PUT | `/api/admin/users/{id}` | Edit akun/profil/role/foto |
| GET | `/api/admin/users/{id}/photo` | Foto privat untuk Superadmin |
| POST | `/api/admin/users/{id}/status` | Aktif/nonaktif |
| POST | `/api/admin/users/{id}/invite` | Kirim ulang aktivasi pending |
| POST | `/api/admin/users/{id}/reset-password` | Kirim tautan reset akun aktif |
| DELETE | `/api/admin/users/{id}` | Soft delete dengan `confirmed` |
| POST | `/api/set-password/inspect` | Validasi token sebelum formulir |
| POST | `/api/set-password` | Konsumsi token dan simpan password |
| PUT | `/api/account/password` | Ganti password pribadi dengan password saat ini |

Query daftar: `search`, `role`, `status`, `sort`, `direction`, `page`, dan `per_page`. Status filter: `active`, `inactive`, `pending_activation`, atau `deleted`. Unggahan multipart untuk edit dapat memakai `POST` dengan `_method=PUT`.

## Database dan konfigurasi pengiriman

Migration aditif `2026_10_24_000800_add_user_management_lifecycle.php` menambahkan nomor telepon, flag pergantian password/aktivasi, versi sesi, `deleted_at`, dan indeks administratif pada tabel pengguna yang ada. Migration telah berhasil diterapkan pada database utama lokal melalui `php artisan migrate --force`. **Tidak ada seeding atau migrasi fresh pada database utama.**

Konfigurasi lokal saat implementasi memakai `MAIL_MAILER=log`; karena itu **aktivasi/reset belum mengirim email eksternal**. Tidak ada klaim bahwa pengiriman nyata telah berhasil. Pembuatan akun dengan password manual tetap dapat digunakan.

Untuk production, atur mailer SMTP yang benar beserta `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, identitas pengirim, dan TLS sesuai penyedia. Gunakan `APP_URL` berupa URL production **HTTPS** agar tautan email menuju domain yang benar; aktifkan cookie sesi aman pada HTTPS. Setelah perubahan konfigurasi, perbarui cache konfigurasi sesuai prosedur deployment dan verifikasi pengiriman nyata ke alamat uji yang diizinkan. Kredensial SMTP tidak boleh disimpan dalam dokumentasi atau repository.

## Hasil pengujian

| Pemeriksaan | Hasil |
| --- | --- |
| Seluruh tes backend Laravel | **170 lulus, 1.779 assertions** |
| Tes frontend | **16 lulus** |
| Skenario browser terfokus untuk fitur/integrasi | **14 lulus** |
| Regresi browser menyeluruh | **67 skenario unik lulus** |
| Build production | **Berhasil** |
| Migration aditif database utama lokal | **Berhasil** |

Cakupan backend meliputi kedua role, password manual dan aktivasi/reset, token hash/expiry/single use, email/status usang, validasi/duplikasi, transaksi gagal, foto privat, filter/statistik, audit, RBAC, sesi, proteksi akun sendiri/Superadmin terakhir, serta preservasi hasil dan sertifikat. Regresi perubahan email juga memverifikasi bahwa token alamat lama tidak dapat dipakai pada akun lain yang kemudian menggunakan alamat tersebut.

Tes otomatis memakai database pengujian terisolasi. Server PHP lokal sempat berhenti saat regresi; skenario tersisa dilanjutkan dan laporan placement yang kehilangan koneksi lulus saat diulang dengan fixture baru. Hasil dihitung berdasarkan 67 skenario unik yang telah lulus. Verifikasi visual desktop/mobile juga selesai, termasuk perbaikan label role agar tidak terpecah menjadi beberapa baris. Pengiriman SMTP eksternal belum termasuk hasil pengujian ini.

Bukti pengujian tersimpan pada `tmp/qa/user-management-final-summary.json`, `user-management-php-tests.txt`, `user-management-frontend-tests.txt`, `user-management-build.txt`, serta laporan browser `user-management-resume-report.json` dan `user-management-retry-report.json`.
