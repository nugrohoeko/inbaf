# Profil akun Superadmin

Klik avatar atau nama pada kanan atas dashboard untuk membuka **My Profile**, **Change Password**, atau **Sign out**. Menu mendukung klik di luar, Escape, tombol panah, Home dan End. Nama serta foto yang berhasil disimpan langsung digunakan oleh header dan menu akun.

## Profil dan foto

Halaman **My Profile** menampilkan nama, email, role, tanggal pembuatan akun dan waktu login terakhir. Login memakai email; sistem tidak menambahkan username. Role serta tanggal akun hanya dapat dilihat melalui profil mandiri.

Pilih **Edit Profile** untuk mengubah nama atau email. **Change Photo** menerima JPG/JPEG, PNG dan WebP hingga 2 MB. Dialog crop menyediakan pratinjau lingkaran, drag foto, zoom, serta kontrol posisi yang dapat dioperasikan melalui keyboard. **Use this photo** menyiapkan hasil crop; foto belum tersimpan sampai **Save Changes** dipilih. **Cancel** membatalkan perubahan. **Remove Photo** meminta konfirmasi dan memakai inisial nama setelah perubahan disimpan.

Batas unggahan dapat diatur melalui `PROFILE_PHOTO_MAX_KB` (default `2048`). Server juga membatasi dimensi 4096 piksel dan 12 megapiksel, mendekode gambar lalu menyimpan ulang pikselnya sebagai JPEG dengan nama UUID. File asli, metadata dan payload tambahan tidak disalin. Foto berada di Laravel Storage privat pada `profile-photos/{user_id}/`; unduhan hanya melayani foto milik Superadmin yang sedang login. Foto sebelumnya dibersihkan setelah transaksi penggantian/penghapusan berhasil. Sertakan direktori Storage privat dalam backup.

## Password dan sesi

**Change Password** memerlukan password saat ini, password baru dan konfirmasi. Password baru minimal 8 karakter, memakai huruf besar/kecil, angka dan simbol, berbeda dari password lama, serta maksimal 72 byte UTF-8 agar sesuai batas bcrypt. Form menyediakan show/hide dan indikator persyaratan. Pesan validasi tidak menyimpan password ke log audit.

Endpoint dibatasi lima permintaan per menit. Setelah berhasil, hash password dan remember token diperbarui, sesi serta CSRF diregenerasi, dan sesi lain dibatalkan. Perangkat saat ini tetap login. Middleware `auth.session` menolak sesi dengan hash lama; untuk driver database, baris sesi lain juga dihapus. Login berhasil mencatat `last_login_at`.

**Sign out** memakai logout Laravel, invalidasi sesi dan regenerasi CSRF. Halaman privat mengharuskan autentikasi, respons SPA/API memakai `Cache-Control: no-store, private`, dan pemulihan halaman dari browser history memeriksa sesi kembali. Logout juga diteruskan ke tab aplikasi lain pada origin browser yang sama.

## Integrasi

| Endpoint | Fungsi |
| --- | --- |
| `GET /api/account` | Profil sendiri dan batas unggahan |
| `PUT /api/account` | Nama/email serta foto opsional atau `remove_photo` |
| `GET /api/account/photo` | Foto privat akun yang sedang login |
| `PUT /api/account/password` | Perubahan password dengan rate limit |

Endpoint akun digunakan bersama oleh Superadmin dan peserta aktif, dengan akses hanya ke akun milik sesi saat ini. Formulir Superadmin tetap membatasi perubahan pada nama, email dan foto; data tambahan peserta tidak dapat disimpan ke profil Superadmin. Upload memakai multipart `POST /api/account` dengan `_method=PUT`. Field role, ID pemilik, tanggal historis, path file dan password ditolak pada formulir profil. Respons berhasil membawa `user`, `csrf_token` dan pesan. Lihat [profil peserta](participant-profile.md) untuk field serta jalur kompatibilitas peserta.

Migrasi `2026_10_23_000600_superadmin_profile_fields` menambah `avatar_path`, `avatar_version` dan `last_login_at` yang nullable. Kolom nama/email/role/tanggal akun dan mekanisme autentikasi yang ada tetap digunakan. Kredensial akun yang sudah ada tidak diganti oleh migrasi.
