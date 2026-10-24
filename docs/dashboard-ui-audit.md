# Audit dan perbaikan UI/UX Dashboard INBAF

Tanggal: 24 Oktober 2026. Cakupan mengikuti route Laravel dan pemilih halaman Vue yang benar-benar tersedia. Modul yang tergabung dalam satu halaman tetap diaudit sebagai subfitur, tanpa membuat halaman duplikat.

## Inventaris halaman dan subfitur

| Role / area | Route yang tersedia | Subfitur yang dicakup |
| --- | --- | --- |
| Kedua role | `/dashboard` | Overview, filter jenis/paket/periode, KPI, aktivitas, distribusi level, kompetensi, rekomendasi paket, aktivitas terbaru; ekspor laporan CSV untuk Superadmin |
| Superadmin | `/admin/questions` | Bank soal, pencarian/filter, bulk actions, impor/ekspor/template, semua jenis soal, opsi/kunci, matching/ordering/dropdown, writing/speaking dan rubrik, grup/media, versi/izin, preview, riwayat pemakaian |
| Superadmin | `/admin/packages` | Paket simulasi/placement/sertifikasi, jadwal dan akses, durasi, bagian/kompetensi/bobot, pemilihan soal manual/acak, batas percobaan, kebijakan, persyaratan sertifikat |
| Superadmin | `/admin/participants` | Daftar, pencarian, profil/identitas, status akun, formulir pembuatan/pengubahan peserta |
| Superadmin | `/admin/competencies` | Kompetensi, kode, warna/urutan, status |
| Superadmin | `/admin/groups` | Teks bacaan, audio/listening, video/gambar, konten bersama dan media tertaut |
| Superadmin | `/admin/media` | Unggahan gambar/audio/video/PDF, metadata dan tindakan pengelolaan |
| Superadmin | `/admin/reviews` | Antrean jawaban writing/speaking, pemutaran respons, rubrik, nilai dan umpan balik |
| Superadmin | `/admin/certificates` | Nomor/pemilik/nilai/status/tanggal, PDF, verifikasi dan pencabutan |
| Superadmin | `/admin/levels` | Skala ambang level, kode, urutan dan deskriptor |
| Superadmin | `/admin/audit` | Riwayat aktivitas, pencarian, pagination, detail event |
| Superadmin | `/admin/settings` | Identity, Branding, Assessment, Certificates, Security, Notifications; kebijakan terpisah ketiga jenis tes dan metode/skala penilaian |
| Peserta | `/tests?test_type=practice`, `placement`, `certification` | Pilihan jenis tes, daftar paket, pencarian, durasi/kompetensi/status/ketersediaan |
| Peserta | `/tests/:id` | Instruksi, kebijakan, bagian/bobot, pemeriksaan perangkat, kode akses, persetujuan dan mulai/resume |
| Peserta | `/exam/:id` | Instruksi, timer, progres, daftar soal, seluruh tipe respons, listening/audio/video, bacaan, writing, speaking, review, previous/next, autosave dan submit |
| Peserta | `/results?test_type=practice`, `placement`, `certification` | Riwayat per jenis, status/nilai/level/tanggal, resume/detail |
| Peserta / hasil yang diizinkan | `/results/:id` | Skor/level, status penilaian, rincian kompetensi, grafik, kekuatan/fokus latihan, perbandingan, answer review yang diizinkan, laporan placement dan sertifikat |
| Peserta | `/certificates` | Sertifikat digital, pemilik, nomor/status/masa berlaku, unduh PDF dan verifikasi |
| Kedua role | `/profile`, `?edit=1`, `?tab=password` | Data akun, profil/edit, foto/crop/hapus, validasi, password/reveal, navigasi akun dan logout |
| Kedua role | `/architecture` | Arsitektur dan alur kerja asesmen yang sudah tersedia |
| Publik | `/`, `/login`, `/register`, `/verify`, `/verify/:token` | Navigasi publik, login/registrasi, intent jenis tes, verifikasi valid/tidak ditemukan/kedaluwarsa/dicabut |

Grammar, vocabulary, reading, listening, writing dan speaking mengikuti kompetensi/jenis soal serta konfigurasi paket dari backend. Jadwal berada dalam editor paket, laporan/analitik berada pada dashboard dan hasil, dan rubrik berada dalam builder serta penilaian manual.

## Temuan utama dan perubahan

- Gaya dasar grid, kartu, tabel, toolbar, formulir dan modal belum lengkap. `resources/css/layout.css` menyediakan primitif bersama dengan token spacing 4/8 px, gap 16/24 px, radius, batas konten 1600 px, padding responsif, dan lapisan header/drawer/modal/toast.
- Warna dark mode lama menimpa emerald dengan biru. Token sekarang mengikuti emerald `#092522`, surface `#102F27`, card `#193C31`, lime `#BEF78F`, mint `#A9D99D`, teks `#E8F1EB` dan sage `#B5C9BE`; light mode tetap tersedia. Warna institusi disimpan sebagai token tersendiri sehingga tidak menimpa aksen dark mode.
- Header workspace sekarang sticky. Sidebar dapat diciutkan di desktop dan menjadi drawer pada layar sampai 1100 px, dengan overlay, scroll, fokus terkurung, Escape, pengembalian fokus, nama menu dan status aktif yang dapat dibaca teknologi bantu.
- Breadcrumb mengenali detail persiapan/hasil. Skip link dan fokus konten setelah perpindahan route mendukung navigasi keyboard.
- Konflik arah flex pada header halaman menyebabkan tombol aksi berada di luar layar. Layout header/action sekarang membungkus ke baris yang tersedia.
- Semua kolom tabel dipertahankan. Scroll horizontal dibatasi ke container tabel, dengan fokus keyboard, caption/petunjuk, toolbar/filter yang membungkus dan pagination konsisten. Empty state admin berada di luar area geser sehingga langsung terlihat.
- Formulir memakai kontrol besar, jarak label/input yang konsisten, section logis dan susunan satu kolom pada layar kecil. Modal panjang dibatasi viewport, dapat digulir dan mempertahankan akses ke tombol footer.
- Builder dan modal bertingkat memiliki pengelolaan fokus, Escape, pemulihan fokus, scroll lock dan background inert. Profil/crop memakai dialog serta kontrol yang tetap terjangkau di ponsel.
- Halaman tes, readiness, hasil, sertifikat, profil dan autentikasi mendapat perbaikan wrapping, struktur kartu, label dan state error/loading. Tidak ada pilihan font baru yang ditambahkan; preferensi ukuran teks yang sudah ada dipertahankan.
- Reading memakai dua kolom jika lebar kontennya cukup, lalu bertumpuk pada layar kecil. Timer tetap terlihat dan digit tidak dibungkus; daftar soal dan modal submit mendapat pengelolaan fokus yang sama.
- Grafik menyesuaikan tinggi/jumlah label, memakai token tema, label besar, legenda HTML dan rincian nilai yang dapat dibuka dengan keyboard/touch. Label diukur setelah font selesai dimuat dan dibungkus berdasarkan lebar piksel, termasuk kata panjang tanpa spasi, agar glyph pertama/terakhir tidak terpotong. Semua nilai tetap berasal dari backend.
- Ikon yang sebelumnya jatuh ke ikon lingkaran pengganti telah dipetakan ke ikon yang sesuai.

## Verifikasi

Matriks utama sudah lulus untuk **320, 375, 768, 1024, 1366, 1440 dan 1920 px**, dengan light/dark mode: 13 halaman Superadmin, 15 variasi route Peserta dan empat halaman formulir/verifikasi publik, total **448 kombinasi role/route/viewport/tema**. Seluruh tab pengaturan, tiga kebijakan jenis tes, builder/modal bertingkat dan validasi backend 422 ikut diperiksa. Layar ujian, detail hasil dan grafik memiliki pemeriksaan tambahan pada tujuh lebar yang sama dan ukuran teks yang diperbesar.

Screenshot yang dirender telah ditinjau untuk desktop/mobile: daftar paket dan tabel admin, matching/question builder, editor paket setelah digulir sampai akhir, profil/edit/password/crop, readiness, login/verifikasi, reading split-screen dan grafik hasil. Bukti tersimpan di `tmp/ui-final-results/`, `tmp/ui-completion-results/` dan `tmp/qa/ui-audit/`. Screenshot penuh diambil dari bagian atas halaman agar posisi header sticky tidak menghasilkan artefak di tengah gambar.

| Pemeriksaan | Hasil akhir |
| --- | --- |
| Build produksi Vite | Lulus |
| Tes frontend | 13/13 lulus |
| Tes PHP unit/feature | 131/131 lulus, 1.198 assertion |
| Skenario browser unik | 51/51 lulus setelah perbaikan dan pengujian ulang |
| Matriks responsif utama | 448 kombinasi lulus, tujuh lebar, kedua tema |
| Pemeriksaan akhir grafik | 3/3 lulus: ujian/hasil, analitik/editor keyboard, resize lalu langsung berpindah halaman |
| Label canvas | Batas glyph label benar-benar diukur pada tujuh lebar dan teks extra large 320 px; tidak keluar canvas |
| Error JavaScript / Laravel | Tidak ditemukan uncaught JavaScript dalam skenario yang diperiksa; file log Laravel tidak bertambah selama QA |

51 skenario unik diselesaikan dalam beberapa run terkoordinasi: enam skenario yang sudah lulus dipertahankan, 45 skenario lanjutan lulus, lalu tiga skenario yang terdampak penyempurnaan grafik dijalankan ulang dan lulus. Kegagalan awal berupa overflow action header, label wajib yang mengubah nama kontrol, dropdown melewati viewport, dan selector/sinkronisasi pada tes sudah diperbaiki. Ringkasan hasil terbaru per skenario tersedia di `tmp/qa/ui-final-summary.json`; laporan mentah masing-masing run juga dipertahankan di `tmp/qa/`.

Regresi fungsional meliputi login/logout dan route privat, sinkronisasi lintas tab, edit profil/foto/crop/hapus, password dan pencabutan sesi lain, preferensi bahasa/ukuran teks, bank soal/versioning/duplikasi/kerahasiaan/usage history, paket dan kebijakan setiap jenis tes, impor/ekspor backend, autosave/pemulihan jawaban, timer server per soal, listening/audio/image/video, speaking dengan mikrofon simulasi, unggahan/playback rekaman, penilaian manual, finalisasi nilai, pemisahan hasil per jenis tes, laporan placement PDF, sertifikat PDF/verifikasi dan nama pemilik sertifikat yang tetap setelah perubahan profil.

Tidak ada masalah layout kritis yang masih ditemukan pada matriks pengujian ini.

Pengujian browser memakai build produksi melalui `tests/Browser/router.php`, dengan `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=<workspace>/tmp/browser.sqlite`, `DB_URL` kosong, cache array dan sesi file. Setup memeriksa isolasi sebelum melakukan migrasi/seed; database aplikasi utama dan penanda Vite milik developer tidak diubah.

Untuk mengulang QA, jalankan server pada terminal terpisah dari root proyek:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=Join-Path (Get-Location) 'tmp/browser.sqlite'
$env:DB_URL=''
$env:SESSION_DRIVER='file'
$env:CACHE_STORE='array'
$env:QUEUE_CONNECTION='sync'
php -S 127.0.0.1:8001 -t public tests/Browser/router.php
```

Lalu jalankan `npm.cmd run build`, `npm.cmd run test:frontend`, `npm.cmd run test:browser` dan `php artisan test`. Browser suite memakai kredensial fixture yang sudah dikonfigurasi secara lokal; tidak ada kredensial yang ditulis pada laporan atau screenshot formulir yang diisi.

Catatan domain terperinci:

- [Superadmin](admin-ui-audit-notes.md)
- [Peserta, akun dan publik](participant-ui-audit-notes.md)
- [Ujian, hasil dan analitik](exam-ui-audit-notes.md)

## Batas perubahan

Tidak ada perubahan endpoint, payload utama, aturan ujian, kunci jawaban, autosave, timer server, scoring, kebijakan percobaan, penerbitan/PDF/tanda tangan sertifikat, atau skema database. Perubahan store hanya memisahkan token aksen institusi dari token warna tema.

Matriks browser memeriksa Chromium pada viewport yang ditentukan. Pemeriksaan ini tidak menggantikan pengujian perangkat fisik atau pembaca layar. Batas yang benar-benar belum teruji akan dicatat pada hasil akhir, tanpa mengklaim seluruh kombinasi data produksi telah diperiksa.
