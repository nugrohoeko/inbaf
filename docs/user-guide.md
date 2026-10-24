# Panduan penggunaan INBAF

Dashboard menyediakan **Tes Simulasi**, **Tes Penempatan**, dan **Tes Sertifikasi**. Pilih jenis sebelum memilih paket; riwayat dan grafik dapat difilter per jenis. Simulasi tidak menerbitkan sertifikat. Penempatan menyediakan klasifikasi awal dan tombol **Cetak laporan penempatan** setelah hasil final. Sertifikasi menerbitkan sertifikat sesuai syarat dan penilaian final.

Rata-rata dan skor terbaik dashboard memakai skala 0–100. Skor setiap hasil tetap memakai skala yang berlaku ketika ujian dimulai, misalnya 450/600. Dengan demikian, perubahan skala antarujian tidak membuat perbandingan dashboard keliru. Grafik aktivitas menampilkan jumlah ujian setiap bulan. Filter dashboard dan riwayat mengikuti pilihan terbaru meskipun beberapa permintaan sebelumnya terlambat selesai.

Gunakan **Ukuran teks / Text size** pada header workspace atau ujian untuk Normal, Large, atau Extra Large. Pilihan disimpan pada akun dan tidak mengubah timer. Navbar publik selalu terlihat di atas saat scroll dan tidak memiliki dropdown ukuran teks; halaman publik tetap memakai preferensi ukuran teks yang sudah dipilih. Sesi tanpa timer menampilkan keterangan khusus; sesi bertimer memakai waktu server. Diagram arsitektur dan alur tersedia di footer workspace.

Superadmin mengelola aturan melalui **Settings → Test types**, memilih jenis dan override pada paket, serta mengatur izin penggunaan/kerahasiaan/batas pemakaian pada soal. Riwayat penggunaan menampilkan versi dan sesi. Lihat [panduan konfigurasi dan upgrade](test-types.md).

## Peserta

Menu avatar/nama pada kanan atas menyediakan **My Profile**, **Edit Profile**, **Change Password**, dan **Sign out**. Peserta dapat mengelola data pribadi, foto dengan crop, dan bahasa antarmuka yang tersimpan pada akun. Perubahan nama tidak mengganti nama pada hasil atau sertifikat historis. Logout tetap mempertahankan jawaban yang sudah tersimpan; resume mengikuti waktu dan kebijakan ujian yang sama. Lihat [panduan profil peserta](participant-profile.md).

1. Buka aplikasi, pilih bahasa ID/EN dan daftar akun. Jika pendaftaran ditutup, minta akun kepada pengelola institusi.
2. Masuk, buka profil dan periksa nama lengkap. Nama pada saat ujian dimulai menjadi bagian dari catatan historis dan sertifikat; perubahan profil sesudahnya tidak mengganti sertifikat yang sudah terbit.
3. Buka daftar tes, pilih paket dan baca durasi, sesi, ketentuan serta batas kesempatan. Siapkan koneksi dan izinkan mikrofon jika ada speaking. Gunakan HTTPS atau localhost untuk mengakses mikrofon.
4. Mulai ujian setelah menyetujui ketentuan. Pada sesi bertimer, waktu tetap berjalan saat tab ditutup atau koneksi terputus. Paket dapat membatasi urutan dan waktu sesi.
5. Jawab soal, gunakan navigasi dan tandai soal yang perlu diperiksa. Perhatikan indikator penyimpanan. Saat kembali terhubung, tunggu sinkronisasi selesai sebelum submit.
6. Untuk listening, putar stimulus sesuai batas yang ditampilkan. Pada pemutar yang sedang terbuka, pause lalu melanjutkan audio tetap memakai satu kesempatan; memutar ulang setelah selesai memakai kesempatan berikutnya bila tersedia. Untuk speaking, selesaikan persiapan, rekam, dengarkan hasil dan pastikan upload berhasil. Bila upload gagal, gunakan retry sebelum waktu ujian berakhir.
7. Kirim ujian. Untuk sesi bertimer, sistem juga menyelesaikan attempt ketika waktu server habis. Jawaban sesudah deadline tidak diterima.
8. Buka hasil. Jika ada esai/speaking yang menunggu penilaian, skor akhir dan sertifikat belum tersedia. Setelah admin selesai menilai, hasil berbobot dan klasifikasi kemampuan tersedia. Jika pembahasan diizinkan, pasangan dan isian ditampilkan dengan label jawaban yang terbaca. Jawaban benar ditampilkan untuk soal otomatis yang memiliki kunci; esai, speaking dan soal manual tidak menampilkan baris jawaban benar. Soal rahasia tidak disertakan dalam pembahasan.
9. Buka sertifikat dan unduh PDF jika memenuhi syarat. Pindai QR atau gunakan halaman Verify Certificate untuk memeriksa status terbaru.

Jangan membagikan password. Token sertifikat memang dapat digunakan publik untuk verifikasi identitas yang tercantum pada sertifikat. Halaman verifikasi tidak menampilkan email, jawaban ujian atau alasan pencabutan.

Soal tertentu dapat memiliki countdown sendiri. Waktu dimulai pada aktivasi pertama dan tetap berjalan ketika Anda mengunjungi soal lain, reload atau kehilangan koneksi. Kembali ke soal tidak menambah waktu. Setelah batas habis, jawaban terakhir yang sudah diterima server dipertahankan; perubahan susulan ditolak dan Anda dapat melanjutkan soal lain. Soal tanpa batas khusus tetap mengikuti durasi sesi dan ujian.

## Superadmin: menyiapkan asesmen

Menu avatar/nama di kanan atas menyediakan **My Profile**, **Change Password**, dan **Sign out**. Melalui **Edit Profile**, ubah nama/email atau pilih foto, atur crop lingkaran, lalu **Save Changes**. Foto maksimal 2 MB dengan format JPG/JPEG/PNG/WebP. Role dan tanggal akun hanya ditampilkan. Password baru memerlukan password saat ini dan konfirmasi; perubahan yang berhasil mengakhiri sesi lain dan mempertahankan sesi perangkat ini. Lihat [panduan profil Superadmin](superadmin-profile.md).

1. Buka pengaturan identitas, branding, registrasi, skala nilai dan kebijakan sertifikat. Isi nama lembaga dan signer yang benar sebelum peserta memulai ujian.
2. Kelola kompetensi dan rentang level. Ambang level serta batas lulus dinilai pada normalisasi 0..100, meskipun skala nilai tampilan berbeda.
3. Upload media audio, gambar atau video ke Media. Berkas stimulus disimpan privat. Hubungkan media atau bacaan ke kelompok stimulus agar dapat digunakan beberapa soal.
4. Buat soal dalam status draft. Pilih tipe dan kompetensi, masukkan instruksi, prompt, opsi dan schema jawaban. Untuk esai/speaking, tentukan rubrik; untuk audio tentukan kebijakan replay.
5. Gunakan preview untuk memeriksa tampilan peserta. Aktifkan soal setelah kunci, opsi, metadata dan stimulus lengkap. Simpan versi baru saat mengubah soal; attempt lama tetap memakai snapshot lama.
6. Buat paket. Atur durasi total, jumlah kesempatan, nilai lulus, kebijakan sertifikat, periode, pemilihan manual/acak, serta sesi yang diperlukan. Setiap sesi memiliki kompetensi, jumlah soal, durasi dan bobot.
7. Pastikan jumlah soal aktif mencukupi. Untuk pilihan manual, pilih soal yang sesuai dengan kompetensi sesi. Susun urutan sesi dan publikasikan paket.
8. Jalankan satu uji menggunakan akun peserta terpisah. Periksa stimulus, timer, penyimpanan, hasil dan PDF sebelum mengundang peserta institusi.

Saat mengedit paket sertifikasi lama, editor menampilkan kebijakan efektif lama sebagai override, termasuk skala skor. Menyimpan perubahan paket mempertahankan skala itu. Kosongkan override skala hanya jika Anda ingin paket mewarisi skala pada jenis tes; sesi yang sudah dimulai tetap mengikuti snapshot semula.

Bobot adalah perbandingan relatif, bukan persentase yang harus selalu berjumlah 100. Contoh bobot sesi 2 dan 1 memberi kontribusi dua pertiga dan sepertiga pada skor total. Bobot soal bekerja di dalam sesi; bobot sesi bekerja pada agregasi akhir.

Durasi per soal opsional. Kosongkan untuk mengikuti waktu sesi/ujian saja. Jika ditentukan, waktu berjalan sejak aktivasi pertama dan tidak dapat diperpanjang dengan revisit. Untuk speaking, berikan waktu cukup untuk persiapan, merekam dan upload. Mengubah durasi bank soal tidak mengubah attempt yang sudah dimulai.

## Penilaian manual

Buka menu review dan pilih jawaban menunggu. Baca esai atau putar rekaman, gunakan rubrik, masukkan nilai 0..100 dan feedback, lalu simpan. Catatan menyimpan reviewer, nilai, rincian rubrik dan feedback. Selama komponen wajib yang dijawab masih menunggu penilaian, attempt belum memiliki skor final. Jawaban kosong mendapat nol.

Review terakhir memicu agregasi hasil dan penerbitan sertifikat jika memenuhi kebijakan historis. Penilaian ulang terhadap hasil yang sudah final ditolak untuk menjaga kesesuaian hasil dan sertifikat. Jika diperlukan koreksi administratif setelah final, tentukan prosedur pembatalan/pencabutan dan ujian ulang dengan pengelola sistem; jangan mengubah tabel langsung.

## Sertifikat dan verifikasi

Template modern dan formal menampilkan hasil final, identitas lembaga dan signer yang tersimpan ketika kebijakan attempt dibuat. Pilih kertas A4/Letter dan orientasi landscape/portrait melalui pengaturan Certificates. Upload gambar logo, background atau signature ke Media, lalu pilih asset yang sesuai. Gambar raster PNG/JPEG/WebP dibatasi 10 MB dan 24 megapiksel untuk sertifikat; SVG upload tidak digunakan. Background ditampilkan cover pada opacity 8%. Signature gambar hanya boleh diunggah oleh personel yang berwenang dan tetap disertai nama/jabatan signer.

Ukuran, orientasi, template dan asset mengikuti snapshot saat ujian dimulai. Mengubah pengaturan tidak mengganti sertifikat historis. Penggantian isi file gambar yang sudah terikat pada sertifikat ditolak; simpan gambar asli dalam backup. Masa berlaku nol berarti tanpa kedaluwarsa. QR mengikuti domain `APP_URL` deployment.

- **VALID**: tanda tangan cocok, tidak dicabut dan masa berlaku belum berakhir.
- **EXPIRED**: integritas data cocok tetapi masa berlaku sudah berakhir.
- **REVOKED**: dicabut oleh superadmin; alasan tersedia pada audit internal.
- **NOT FOUND**: token tidak ditemukan atau pemeriksaan integritas gagal.

Untuk mencabut sertifikat, buka manajemen sertifikat, pilih revoke dan masukkan alasan yang jelas. Pencabutan tidak menghapus catatan historis. PDF adalah salinan; pihak penerima harus memeriksa status publik terbaru.

## Import dan export soal

Unduh template CSV atau Excel `.xlsx` dari bank soal. Pertahankan nama serta urutan kolom; untuk CSV gunakan UTF-8. Kolom `options`, `answer_schema` dan `metadata` menggunakan JSON; contoh tersedia di template. Gunakan ID kompetensi dan kelompok stimulus yang sudah ada.

Import CSV dan XLSX dibatasi 500 baris per berkas dan 5 MB. Semua baris divalidasi sebelum disimpan; perbaiki kesalahan baris dan kirim ulang. Kode soal harus unik. Gunakan satu sheet template saat bekerja di Excel. Export menyertakan kunci jawaban dan hanya boleh diberikan kepada personel berwenang.

## Pemecahan masalah

| Gejala | Tindakan |
| --- | --- |
| Mikrofon tidak tersedia | Gunakan HTTPS/localhost, periksa izin situs dan perangkat, coba browser yang mendukung MediaRecorder |
| Rekaman gagal upload | Periksa koneksi, batas ukuran/format dan waktu tersisa; gunakan retry sebelum sesi berakhir |
| Tidak dapat menjawab sesi | Periksa apakah sesi belum dimulai, sudah ditutup atau attempt sudah selesai |
| Tidak dapat mulai tes | Periksa status paket, periode, jumlah soal aktif, akun aktif dan batas kesempatan |
| Hasil belum final | Periksa jawaban esai/speaking yang masih menunggu review |
| PDF gagal atau QR salah domain | Periksa GD, `APP_KEY`, `APP_URL` dan konfigurasi cache pada server |
| PDF menolak integritas gambar | Pulihkan asset asli dari backup; mengunggah pengganti tidak mengganti hash sertifikat yang sudah final |
| Sertifikat lama tidak valid setelah deploy | Pulihkan `APP_KEY` asli yang sesuai dengan catatan; jangan membuat key baru sembarangan |
| Attempt tidak tertutup saat browser tutup | Periksa scheduler, worker queue, failed jobs dan `inbaf:expire-attempts` |

Lihat `docs/deployment.md` untuk operasi server dan `docs/requirements.md` untuk batas implementasi serta pemeriksaan institusi sebelum penggunaan resmi.
