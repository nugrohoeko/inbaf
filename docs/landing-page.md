# Landing page INBAF

Landing memakai konsep original **Your voice. A wider world.** dengan hero editorial emerald, pita bahasa SVG, aksen mint dan violet, serta latar pearl pada konten. Visual dibuat dalam kode; tidak menggunakan layout atau ilustrasi landing lama.

## Navigasi dan interaksi

- Navbar sticky menyediakan Home, About INBAF, Proficiency Levels, tiga jenis asesmen, verifikasi sertifikat, bahasa, tema dan akses akun. Pada layar kecil menu dapat ditutup dengan Escape atau klik di luar. Tinggi header diukur agar target anchor tidak tertutup.
- Panel asesmen memperkenalkan Simulation Test, Placement Test dan Certification Test. Tombol membuka kategori paket yang sebenarnya; status aktif serta jumlah paket mengikuti data bootstrap.
- Pilihan jenis asesmen diteruskan melalui halaman daftar/masuk menggunakan allowlist tiga kode internal. Setelah autentikasi, peserta menuju kategori yang dipilih; login biasa dan login Superadmin tetap menuju dashboard.
- Atlas kompetensi memakai nama, deskripsi, ikon dan konfigurasi kompetensi aktual. Tombol mendukung keyboard dan menjelaskan penggunaan keterampilan dalam konteks sehari-hari.
- Jalur level memakai data level penyelenggara. Contoh ungkapan bersifat ilustratif; tidak menampilkan skor pengguna atau menjanjikan kesetaraan resmi.
- Contoh soal publik memberikan umpan balik lokal tanpa membuat attempt. Form verifikasi membuka endpoint verifikasi sertifikat yang sudah ada.

## Aksesibilitas dan konfigurasi

Bahasa Inggris dan Indonesia tersedia. Logo yang dikonfigurasi, nama sistem, footer, jenis tes, paket, kompetensi, level dan preferensi tema tetap digunakan. Root font tetap mengikuti preferensi yang sudah tersimpan: 16, 18 atau 20 px. Seluruh paragraf landing minimal 1.125rem; tidak ada dropdown pengaturan ukuran teks pada navbar publik.

Animasi memakai SVG/CSS dan IntersectionObserver. Mode reduced motion menghentikan animasi, transisi dan reveal. Kontrol memiliki target minimal 44 px, indikator fokus, serta label aksesibel. Kartu dan paragraf diperiksa kontrasnya pada empat konfigurasi terang/gelap, termasuk lebar 320 px dengan Extra Large.

## Batas perubahan

Implementasi berada pada `LandingPage.vue`, `LanguageAtlas.vue`, `PublicNavigation.vue` dan integrasi `PublicPages.vue`. CSS landing dibatasi ke komponen, termasuk kelas tema lokal. Backend autentikasi, API, tabel database, bank soal, scoring, hasil, profil serta sertifikat tidak diubah.

Pengujian fungsional terdapat pada `tests/Browser/landing-redesign.spec.js` dan regresi navbar pada `preferences.spec.js`. Bukti visual dan hasil akhir tersedia pada [hasil verifikasi lokal](verification.md).
