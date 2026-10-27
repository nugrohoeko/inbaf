# Inventaris dan cakupan QA antarmuka final

Tanggal pemeriksaan: 24–25 Oktober 2026. Dokumen ini memetakan antarmuka yang benar-benar ada ke skenario browser. Hasil eksekusi final dan keputusan deployment berada di [laporan QA final](final-qa-report.md); keberadaan tes dalam tabel ini sendiri bukan bukti tes telah lulus.

Sumber inventaris: `resources/js/App.vue`, 25 komponen Vue di `resources/js/components`, route Laravel, serta seluruh berkas `tests/Browser/*.spec.js`. Ada **26 keluarga halaman kanonis**, dengan variasi role, query, tab, dialog, dan status. `/admin/change-password` adalah alias halaman profil/password dan tidak dihitung dua kali. Dashboard dan profil memiliki tampilan untuk kedua role. Route API tidak dihitung sebagai halaman UI.

| Keluarga halaman | Role | Tindakan dan variasi yang diinventarisasi | Bukti skenario browser |
| --- | --- | --- | --- |
| `/` | Publik | Navigasi sticky, logo, pilihan tiga jenis tes, intent login, atlas kompetensi/level, bahasa, tema, anchor, reduced motion, keyboard | `landing-redesign`, `preferences` |
| `/login` | Publik | Login kedua role, validasi, password reveal, redirect role/intent, kegagalan sesi | `user-account-lifecycle`, `workflows`, `participant-account`, `superadmin-profile`, `final-exam-integration` |
| `/register` | Publik | Nama/email/password/konfirmasi, validasi, pendaftaran nyata dan masuk otomatis | `final-exam-integration`, `final-question-widgets`, `participant-layout` |
| `/set-password` | Publik | Aktivasi/reset, tautan kedaluwarsa, password/reveal, konfirmasi, sekali pakai, aksesibilitas form, pemulihan | `set-password`, `user-account-lifecycle` |
| `/verify` dan `/verify/:token` | Publik | Input/verifikasi token, valid, revoked, not found, informasi publik terbatas, nama historis | `final-exam-integration`, `participant-account`, `participant-layout`; expired juga diuji pada backend |
| `/dashboard` | Kedua role | KPI aktual, filter jenis/paket/periode, grafik/nilai tabel, navigasi tes/hasil, pergantian filter cepat dan chart teardown | `exam-layout`, `participant-regressions`, `admin-layout`, `participant-layout` |
| `/profile` | Kedua role | Lihat/edit/cancel, data pribadi, avatar crop/replace/delete, bahasa/preferensi, password/reveal/strength, sesi lain dicabut, logout | `participant-account`, `superadmin-profile`, `preferences` |
| `/architecture` | Kedua role | Diagram/alur, isi terbaca, navigasi kembali | `participant-layout` |
| `/tests` | Peserta | Tiga jenis tes, daftar/search/filter paket, detail, empty state | `landing-redesign`, `test-types`, `workflows`, `participant-layout` |
| `/tests/:id` | Peserta | Instruksi, jadwal/kode akses, durasi/bobot, persetujuan, start/resume; pemeriksaan perangkat bersifat opsional | `workflows`, `test-types`, `participant-account`, `final-exam-integration`, `final-question-widgets` |
| `/exam/:id` | Peserta | Semua 18 tipe respons melalui kombinasi kasus, timer server, previous/next/nomor, review mark, autosave/reload, offline/reconnect, sesi hilang/login ulang, dua tab, submit dan hasil | `final-question-widgets` (16 otomatis), `workflows` (essay), `speaking` (speaking), `final-exam-integration`, `exam-layout` |
| `/results` | Peserta | Riwayat terpisah per jenis, status/score/level, detail/resume, browser Back dan respons tertunda | `test-types`, `participant-regressions`, `final-exam-integration` |
| `/results/:id` | Peserta/akses yang diizinkan | Pending review berbeda dari hasil final, grafik/rincian/rekomendasi, penjelasan yang diizinkan, PDF placement/sertifikat | `workflows`, `test-types`, `exam-layout`, `final-exam-integration`, `final-question-widgets` |
| `/certificates` | Peserta | Nama historis, nomor/status, PDF, verifikasi dan hasil yang konsisten | `participant-account`, `final-exam-integration` |
| `/admin/users` | Superadmin | Create/edit/detail, photo/crop, role confirmation, aktivasi/nonaktif, soft delete/retensi, reset/aktivasi akun, search/filter/sort/page size, self protections | `user-management`, `user-account-lifecycle`, `final-admin-interactions` |
| `/admin/questions` | Superadmin | Create/edit/version/duplicate/archive, preview seluruh tipe, riwayat penggunaan, search/filter/page, bulk status, validasi 422, impor atomik, CSV export/XLSX template | `workflows`, `test-types`, `admin-layout`, `final-admin-interactions` |
| `/admin/packages` | Superadmin | Create/save/edit, manual/random selection, section/competency/weight/duration, tiga jenis dan override independen, jadwal/akses, sertifikat | `workflows`, `test-types`, `admin-layout`; jadwal/akses juga diuji backend |
| `/admin/participants` | Superadmin | Create/edit/deactivate, status pending tetap, profil dan validasi backend ketat | `legacy-user-management`, `admin-layout` |
| `/admin/reviews` | Superadmin | Antrean, respons tulis/rekaman, playback, rubrik berbobot, feedback, finalisasi dan antrean hilang | `speaking`, `workflows`, `final-exam-integration` |
| `/admin/certificates` | Superadmin | Nomor/nama/score/status, PDF/verifikasi, pencabutan dan status publik terbaru | `participant-account`, `final-exam-integration`, `admin-layout` |
| `/admin/competencies` | Superadmin | Create/edit/archive, kode unik/422, nama bilingual/warna/order/status | `final-admin-interactions`, `admin-layout` |
| `/admin/groups` | Superadmin | Create/edit/delete passage, rich text, jenis grup/media link, dialog; grup yang masih dipakai soal tidak boleh dihapus | `final-admin-interactions`, `admin-layout`; penjagaan relasi juga diuji backend |
| `/admin/media` | Superadmin | Unggah gambar/audio/video, metadata, media link terautentikasi | `final-admin-interactions` (PNG/WAV); video starter dibaca pada `workflows`/`final-question-widgets` |
| `/admin/levels` | Superadmin | Edit/validasi threshold/name/order/descriptor, dialog; perubahan nama QA dikembalikan di `finally` | `final-admin-interactions`, `admin-layout`; matriks A1–C2 dan create/validasi juga backend |
| `/admin/audit` | Superadmin | Search/page, detail event, keyboard dismissal | `admin-layout`, `user-management` |
| `/admin/settings` | Superadmin | Identity/Branding/Assessment/Certificates/Security/Notifications; editor kebijakan tiga jenis tes; save/persist setting dan kebijakan independen | `workflows`, `test-types`, `admin-layout` |

## Pembedaan cakupan

- Inventaris tombol mengikuti tindakan yang tersedia. Tidak ada tombol delete pada level, CRUD media lengkap, atau tombol generate sertifikat manual: sertifikat diterbitkan dari hasil final yang memenuhi snapshot kebijakan. Fitur yang tidak ada tidak diklaim telah diuji.
- Label *Simulation Test* dalam brief berpadanan dengan kode domain `practice` dan label UI *Practice Test*. Kode jenis tes `practice`, `placement`, dan `certification` diuji sebagai alur yang berbeda.
- Bank soal memiliki 18 tipe. Tes baru `final-question-widgets.spec.js` menjawab **16 tipe otomatis** melalui widget aslinya, memeriksa respons persisten setelah refresh dan skor referensi 100. Essay dan speaking memiliki jalur pending/manual/final yang berbeda dan diuji terpisah. Pengujian bukan hanya memastikan editor dapat dibuka.
- Speaking menggunakan perangkat mikrofon sintetis Chrome. Bytes benar-benar direkam oleh `MediaRecorder`, diunggah ke API, dibaca melalui endpoint privat, diputar, dan dinilai. Ini tidak membuktikan mikrofon fisik, izin perangkat pengguna, atau jaringan production.
- Kasus offline memutus jaringan browser, mengubah respons/tanda review, lalu menghubungkan kembali. Kasus sesi hilang menghapus cookie sesi nyata lalu menerima respons otorisasi server; ini menguji pemulihan setelah kehilangan autentikasi, bukan menunggu jam kedaluwarsa production.
- Kasus dua tab menguji simpan pada soal berbeda, pemulihan dari server melalui reload, dan finalisasi berulang. Ini tidak mengklaim kolaborasi langsung atau penyatuan otomatis atas dua suntingan serentak pada soal yang sama.
- Beberapa tes regresi lama secara sengaja menunda/mengganti respons untuk race condition UI atau metadata institusi. Alur baru finalisasi, impor, media, respons 16 tipe, offline/recovery, dan sertifikasi menggunakan API serta database sungguhan tanpa mock respons aplikasi.
- Panel lonceng mengambil feed aktivitas nyata dari `GET /api/notifications`: antrean penilaian/ujian aktif untuk admin, serta attempt/hasil/sertifikat milik peserta. Loading, error/retry, empty state, tautan resume/hasil, dan tampilan 320 piksel pada kedua tema diuji pada `final-notifications`. Respons privat tidak di-cache. Feed dimuat saat panel dibuka; tidak ada klaim push realtime. Pengiriman email aktivasi/reset memerlukan layanan mail nyata dan dicatat terpisah dalam kesiapan deployment.
- PDF diuji sebagai unduhan aktual, byte header/EOF dan isi yang tidak kosong. Rendering/QR decoding serta pemeriksaan konsistensi mendalam dirujuk ke pengujian domain/operasional final; tindakan ini tidak berarti printer fisik telah diuji.

## Matriks visual dan aksesibilitas

Suite responsif yang tersedia memeriksa lebar **320, 375, 768, 1024, 1366, 1440, 1920 piksel**, light/dark mode, sticky header, overflow halaman, scroll tabel, dialog panjang, drawer mobile, focus trap/Escape/return focus, labels/field errors, chart labels dan resize. Suite akun/pengguna menambah modal/foto/password pada mobile/tablet/desktop. Preferensi ukuran teks yang telah tersedia tetap dipertahankan; audit ini tidak menambah dropdown baru.

Ini adalah pemeriksaan Chrome desktop/headless dengan emulasi viewport. Safari, Firefox, peramban mobile fisik, screen reader nyata, physical microphone/speaker, keyboard perangkat mobile, dan jaringan/infrastruktur production belum dibuktikan oleh matriks ini. Tidak digunakan klaim lulus WCAG menyeluruh atau bebas bug.

## Temuan antarmuka final

| ID | Dampak | Akar masalah | Perbaikan | Verifikasi |
| --- | --- | --- | --- | --- |
| FUI-01 | Low: pemilih file dan empty-state mengundang unggahan PDF yang pasti ditolak server | `AdminWorkspace.vue` menyebut PDF, sedangkan whitelist `MediaController` menerima gambar/audio/video | Hapus `application/pdf` dari atribut accept dan undangan PDF dari teks Inggris/Indonesia; format backend tetap menjadi aturan | `final-admin-interactions` mengunggah PNG dan WAV nyata; source accept sesuai whitelist; status eksekusi ada di laporan final |
| FUI-02 | Medium: panel notifikasi selalu kosong meskipun ada aktivitas | Panel memakai teks statis tanpa sumber data | Tambahkan endpoint terotorisasi dan `NotificationPanel.vue` dengan aktivitas sesuai role/pemilik, status proses dan tautan internal | Empat tes feature akses/data dan satu browser nyata; hasil final ada di laporan utama |
| QL-01 | High: penghapusan grup dapat melepaskan passage dari soal aktif; label arsip menyesatkan | FK `nullOnDelete` dan operasi delete tanpa pemeriksaan pemakaian | Tolak penghapusan grup terpakai, kunci relasi; label dan konfirmasi UI menyatakan penghapusan permanen grup kosong | `QuestionLifecycleSafetyTest`, `QuestionGroupRaceTest`, dan regresi admin; hasil final ada di laporan utama |

## Bukti dan pengukuran

`final-qa-metrics.js` menyimpan label operasi, metode, status HTTP dan durasi aktual dalam `tmp/qa/final/http/*.json`. Tidak ada URL, query, email, ID pengguna, cookie atau token yang disimpan pada sampel. Kategori meliputi dashboard, bank soal, simpan jawaban, submit, penilaian, PDF, upload/read media, serta import/export. Sampel lokal ini bukan bukti kapasitas atau SLA production.

Saat penyusunan inventaris: **76 kasus browser dalam 21 spec**, termasuk sembilan kasus final (tujuh alur fungsional, satu keamanan, satu feed notifikasi). Tes frontend yang dieksekusi ulang: **17/17 lulus**, termasuk normalisasi rubrik lama. Nilai pass/fail browser final harus diambil dari laporan run terkoordinasi oleh runner utama, bukan dari angka discovery atau baseline audit sebelumnya.
