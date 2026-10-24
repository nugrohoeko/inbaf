# Profil peserta INBAF

Avatar atau nama pada kanan atas dashboard membuka **My Profile**, **Edit Profile**, **Change Password**, dan **Sign out**. Klik di luar menu atau tekan Escape untuk menutupnya. Menu mendukung navigasi keyboard. Avatar juga ditampilkan di dashboard dan halaman profil, dengan inisial nama sebagai pengganti ketika foto belum tersedia.

## Informasi pribadi dan pengaturan akun

**My Profile** menampilkan nama, email, negara asal, kewarganegaraan, bahasa ibu, institusi, nomor telepon, tanggal lahir, gender opsional, bahasa antarmuka, tanggal registrasi, login terakhir dan status akun. Login memakai email; tidak ada field username tambahan. Role, tanggal akun dan status tidak dapat diubah melalui profil mandiri.

Pilih **Edit Profile**, ubah data, lalu **Save Changes**. **Cancel** membatalkan perubahan yang belum disimpan. Nomor telepon menerima format internasional hingga 32 karakter; tanggal lahir menggunakan tanggal yang tidak berada di masa depan. Gender dapat dibiarkan kosong. Nama, email dan avatar yang berhasil disimpan langsung digunakan oleh dashboard dan menu akun.

Bahasa antarmuka tersedia dalam Bahasa Indonesia dan English. Preferensi peserta tersimpan pada akun, diterapkan setelah penyimpanan, dan dipulihkan saat login pada browser lain. Tombol bahasa pada header juga menyimpan pilihan peserta. Pilihan lama di browser dipertahankan untuk akun yang belum memiliki preferensi. Fitur profil tidak menambahkan kontrol ukuran font.

## Foto, password dan logout

**Upload Photo** atau **Change Photo** menerima JPG/JPEG, PNG dan WebP hingga 2 MB, mengikuti `PROFILE_PHOTO_MAX_KB`. Pratinjau crop lingkaran menyediakan drag, zoom dan kontrol posisi. **Use this photo** menyiapkan foto; **Save Changes** menyimpannya. **Remove Photo** meminta konfirmasi dan juga diterapkan setelah penyimpanan. File disimpan melalui layanan foto yang sama dengan Superadmin: nama UUID, dekode dan enkode ulang gambar, penyimpanan privat, akses milik sendiri dan pembersihan foto lama setelah transaksi berhasil.

**Change Password** membutuhkan password saat ini, password baru dan konfirmasi. Kebijakan yang sama dengan Superadmin berlaku: minimal 8 karakter, huruf besar/kecil, angka dan simbol, berbeda dari password lama, maksimal 72 byte UTF-8. Form menyediakan show/hide serta indikator persyaratan. Endpoint password dan jalur kompatibilitas profil lama memakai batas gabungan lima permintaan per menit untuk setiap akun. Password di-hash oleh Laravel; audit hanya mencatat aktivitas tanpa nilainya. Perubahan yang berhasil meregenerasi sesi/CSRF dan mengakhiri sesi lain, sementara perangkat saat ini tetap login.

**Sign out** mengakhiri sesi Laravel, meregenerasi CSRF dan membuka halaman login. Halaman privat memakai autentikasi dan cache `no-store, private`; tab lain pada origin yang sama menerima peristiwa logout. Jawaban yang sudah diterima server tetap berada pada attempt. Setelah login kembali, peserta melanjutkan attempt yang sama hanya jika kebijakan serta waktunya masih mengizinkan. Logout tidak memperpanjang waktu ujian atau deadline soal.

Respons permintaan dari sesi lama tidak boleh menimpa token CSRF atau mengakhiri sesi akun yang baru login. Pemeriksaan revisi sesi pada frontend melindungi pergantian akun dari respons autentikasi yang datang terlambat.

## Integritas hasil dan sertifikat

Perubahan nama profil hanya mengubah akun. Nama pada snapshot ujian, hasil historis, laporan penempatan dan payload sertifikat tetap mengikuti catatan saat ujian dimulai. Kartu sertifikat peserta dan tabel sertifikat Superadmin membaca nama dari payload sertifikat, dengan snapshot historis sebagai fallback. Tanda tangan serta verifikasi sertifikat yang sudah terbit tidak dihitung ulang oleh penyimpanan profil. Koreksi nama sertifikat harus melalui prosedur pengelola yang berwenang dan tercatat dalam audit; formulir profil tidak menyediakan perubahan sertifikat.

## Backend dan penyimpanan

Endpoint akun yang sudah ada digunakan bersama dengan Superadmin; setiap permintaan dibatasi ke akun aktif yang sedang login. Izin fitur administrasi tetap mensyaratkan Superadmin.

| Endpoint | Data atau fungsi |
| --- | --- |
| `GET /api/account` | Profil sendiri, `user.profile`, batas unggahan |
| `PUT /api/account` | `name,email` dan field peserta opsional; foto atau `remove_photo` |
| `GET /api/account/photo` | Foto privat pemilik sesi |
| `PUT /api/account/password` | `current_password,password,password_confirmation` |
| `PUT /api/preferences` | `preferred_language=id|en`, `text_size`, atau keduanya |

Upload memakai multipart `POST /api/account` dengan `_method=PUT`. Field peserta dikirim datar: `country,nationality,native_language,institution,phone,date_of_birth,gender,preferred_language`. Field opsional yang tidak dikirim tetap dipertahankan; `null` mengosongkannya. Role, ID pemilik, status, tanggal akun dan path file tidak dapat diubah melalui payload profil. Respons berhasil membawa `user` dan `csrf_token`; bahasa tersimpan pada `user.preferred_language`, data pribadi pada `user.profile`.

Migrasi `2026_10_24_000700_participant_profile_fields` hanya menambah `phone`, `date_of_birth` dan `gender` nullable pada `participant_profiles`, serta `preferred_language` nullable pada `users`. Kolom identitas, avatar, login terakhir dan data profil sebelumnya dipakai kembali. Tidak ada tabel atau salinan bank soal, hasil maupun sertifikat baru untuk fitur ini.
