# Hasil verifikasi lokal

## Redesign landing page - 24 Oktober 2026

Landing page baru memakai hero editorial dan pita bahasa SVG original, panel tiga jenis tes, atlas tujuh kompetensi, jalur level dinamis, contoh soal tanpa nilai, form verifikasi, FAQ dan footer baru. Navbar sticky mempertahankan akses bahasa, tema, login/profil dan verifikasi. Pilihan jenis tes diteruskan melalui autentikasi dengan allowlist tiga kode internal.

Pengujian awal menemukan tautan verifikasi ganda di desktop dan kontras enam angka indeks yang tereduksi oleh opacity. Kedua masalah diperbaiki sebelum build akhir. Perbandingan headline bilingual pada tes juga disamakan menggunakan rendered text. Bukti awal disimpan pada `tmp/qa/landing-redesign-initial-browser-report.json`.

| Pemeriksaan | Hasil |
| --- | --- |
| Seluruh unit/feature PHP | **131 lulus, 1.198 assertion** |
| Tes frontend Node | **13 lulus** |
| Browser khusus landing | **3 lulus**: intent jenis tes melalui login, atlas/level yang mengikuti metadata dinamis, serta navigasi keyboard/responsif/tema/reduced motion |
| Suite Chrome lengkap pada build akhir | **37 lulus dalam satu run**, 8 file, 12,7 menit; **0 gagal/skipped/flaky**, tanpa error global runner |
| Audit visual akhir | Empat konfigurasi: desktop terang/gelap, smartphone 390 px, serta 320 px gelap Extra Large; **0 overflow dokumen/konten dan 0 error JavaScript** |
| Audit kontras teks | Semua sampel teks yang diperiksa lulus rasio AA sesuai ukuran/ketebalan; seluruh paragraf landing minimal 18 px pada skala normal |
| Tipografi dan motion | Root 16/18/20 px tetap; tanpa dropdown ukuran font; reduced motion menghentikan animasi/transisi landing |
| Pemeriksaan interaksi tambahan | Tujuh catatan lulus: skip link, jawaban contoh salah/benar, lima FAQ dengan keyboard, token verifikasi tidak valid, serta logo/anchor dari halaman verifikasi/login/registrasi; tanpa error JavaScript |
| Build produksi | Berhasil; seluruh ilustrasi landing berupa SVG/CSS lokal |
| Backend dan database utama | Tidak ada perubahan PHP, API, skema atau reset database utama untuk redesign |

Bukti akhir lengkap: `tmp/qa/landing-redesign-full-browser-report.json` pada bundle produksi `app-MRPCBqL5.js`. Bukti visual: `tmp/qa/landing-design-audit.json`, interaksi tambahan `tmp/qa/landing-usability-audit.json`, dan screenshot `tmp/qa/landing-preview-*`. Tes browser memakai SQLite terpisah dan server QA dihentikan setelah selesai; aplikasi utama tetap tersedia pada port 8000. Desain dan batas implementasi dijelaskan pada [panduan landing page](landing-page.md).

## Profil peserta - 24 Oktober 2026

Peserta memakai layanan akun dan foto privat yang sama dengan Superadmin, dengan menu empat pilihan, profil lengkap, preferensi bahasa pada akun, foto/crop dan perubahan password. Avatar dan nama tersinkron ke navbar serta dashboard. Data yang sudah tercatat pada snapshot hasil, sertifikat dan jawaban ujian tetap dipertahankan.

Audit menemukan respons autentikasi dari sesi lama dapat mengakhiri sesi akun yang baru login. Dua tes mereproduksi respons terlambat `401` dan `419` sebelum perbaikan. Frontend kini memeriksa revisi sesi sebelum menghapus akun aktif, dengan tetap menolak permintaan lama tersebut.

Regresi browser tambahan mereproduksi kartu sertifikat yang ikut mengganti nama setelah profil diedit, meskipun payload dan PDF tetap historis. Kartu peserta serta tabel sertifikat Superadmin kini membaca nama dari payload sertifikat. Bukti kegagalan sebelum perbaikan disimpan pada `tmp/qa/participant-certificate-before-fix-browser-report.json`.

| Pemeriksaan | Hasil |
| --- | --- |
| Seluruh unit/feature PHP | **131 lulus, 1.198 assertion** |
| Tes frontend Node | **13 lulus**, termasuk antrean bahasa dan respons autentikasi sesi lama |
| Browser profil peserta | **5 lulus**: menu/profil/bahasa, foto/crop/cancel/responsif, password/sesi lain, logout/resume jawaban dan nama historis sertifikat pada kedua role |
| Suite Chrome lengkap pada build akhir | **34 lulus dalam satu run**, 7 file, 11,7 menit; **0 gagal/skipped/flaky**, tanpa error global runner |
| Integritas hasil dan sertifikat | Snapshot nama, jawaban, payload, tanda tangan dan verifikasi sertifikat tetap utuh setelah perubahan profil |
| Keamanan sesi peserta | Password lama ditolak setelah perubahan; sesi lain dibatalkan, sesi saat ini dan sesi pengguna lain tetap sesuai kebijakan |
| Jawaban dan kelanjutan ujian | Jawaban tersimpan setelah logout; attempt dan deadline asli dipertahankan, masa berlaku tidak diperpanjang |
| Font dan identitas navbar publik | **12 kombinasi**, sama dengan baseline; tanpa dropdown atau overflow horizontal |
| Build produksi dan Composer validate | Berhasil |
| Integrasi MySQL peserta | Detail profil, bahasa, foto privat dan password berhasil; transaksi di-rollback, **0 baris QA tersimpan**, file QA dibersihkan |
| Migrasi lokal | `000700_participant_profile_fields` diterapkan setelah backup privat; kolom tambahan nullable, kredensial utama tetap |

Bukti akhir: `tmp/qa/participant-profile-full-browser-report.json`, menggunakan bundle produksi `app-DmgIb0Rt.js`. Bukti pendukung: `tmp/qa/mysql-participant-profile-smoke.json`, `tmp/qa/participant-profile-navbar-font-audit.json`, `tmp/qa/participant-stale-auth-before-fix.txt`, `tmp/qa/participant-certificate-final-browser-report.json`, dan screenshot `tmp/qa/participant-profile-dark-xl-320.png`, `participant-profile-edit-dark-xl-320.png`, `participant-crop-dark-xl-320.png`, `participant-certificate-after-fix.png`. Tes browser memakai akun QA dan SQLite terpisah. Screenshot otomatis tes password serta tracing dimatikan. Panduan tersedia pada [profil peserta](participant-profile.md).

## Profil Superadmin - 23 Oktober 2026

Menu akun menyediakan profil, perubahan password dan logout. Profil memakai nama/email akun yang ada, role/tanggal read-only, serta foto privat dengan preview/crop lingkaran. Pembaruan langsung diterapkan pada header. Pemeriksaan visual pada 320 px dan Extra Large merapikan tombol profil/crop menjadi vertikal agar label tidak terpecah; ukuran font tidak diubah.

| Pemeriksaan | Hasil |
| --- | --- |
| Seluruh unit/feature PHP | **115 lulus, 864 assertion** |
| Tes frontend Node | **8 lulus**, termasuk respons bootstrap terlambat setelah logout dan sesi API kedaluwarsa |
| Tes keamanan sesi | Termasuk enam kasus dengan sesi database terenkripsi: regenerasi cookie/CSRF, logout, replay cookie, akun nonaktif, pembatalan sesi lain dan kompatibilitas perubahan password peserta |
| Browser profil terarah | **4 lulus**: menu/responsif/cancel, edit/foto/crop/ganti/hapus, password/sesi lain, logout/Back/tab lain |
| Suite Chrome lengkap pada build akhir | **29 lulus dalam satu run**, 6 file, 8,3 menit; **0 gagal/skipped/flaky**, tanpa error global runner |
| Font dan identitas navbar publik | **12 kombinasi**, sama dengan baseline: root/font/logo/background; tanpa dropdown dan overflow horizontal |
| Build produksi dan Composer validate | Berhasil |
| Integrasi MySQL profil | Nama/email, penyimpanan foto privat, password baru/penolakan password lama, serta penghapusan foto berhasil; transaksi di-rollback, **0 baris QA tersimpan**, file QA dibersihkan |
| Migrasi lokal | `000600_superadmin_profile_fields` diterapkan setelah backup privat terverifikasi; kredensial akun utama tidak diganti |

Bukti akhir: `tmp/qa/superadmin-profile-full-browser-report.json`. Bukti pendukung: `tmp/qa/superadmin-profile-targeted-browser-report.json`, `tmp/qa/mysql-profile-smoke.json`, `tmp/qa/superadmin-profile-navbar-font-audit.json`, dan screenshot `tmp/qa/superadmin-profile-photo-saved.png`, `superadmin-profile-dark-xl-320.png`, `superadmin-profile-edit-dark-xl-320.png`, `superadmin-crop-dark-xl-320.png`. Tes browser memakai akun QA khusus pada SQLite terpisah. Screenshot otomatis tes password dimatikan dan tracing tetap nonaktif. Petunjuk pemakaian dan konfigurasi ada pada [panduan profil Superadmin](superadmin-profile.md).

## Audit lanjutan - 23 Oktober 2026

Audit lanjutan memperbaiki perbandingan skor dari skala snapshot berbeda, menjaga skala paket sertifikasi lama ketika diedit, dan menampilkan jumlah impor yang benar. Pembahasan kini menampilkan label pasangan/isian dan tidak menampilkan baris jawaban benar untuk penilaian manual. Pause/resume listening tidak menghabiskan kesempatan baru, dan respons filter yang terlambat tidak menggantikan pilihan terbaru. Ukuran huruf serta navbar publik sticky tanpa dropdown tetap dipertahankan.

Pengujian juga menemukan callback resize grafik yang masih berjalan setelah navigasi menghancurkan canvas. Tes terarah mereproduksi error `ownerDocument` tiga kali sebelum perbaikan. Penundaan resize dihapus pada grafik dashboard dan hasil; tes yang sama kemudian lulus tanpa error. Bukti sebelum/sesudah tersimpan terpisah agar kegagalan awal tetap dapat diperiksa.

| Pemeriksaan | Hasil |
| --- | --- |
| Seluruh unit/feature PHP | **96 lulus, 583 assertion** |
| Tes frontend Node | **6 lulus**; antrean preferensi dan penyimpanan kebijakan paket legacy/inherited/explicit |
| Suite Chrome lengkap pada build akhir | **25 lulus dalam satu run**, 5 file, 6,3 menit; **0 gagal/skipped/flaky**, tanpa error global runner |
| Regresi resize dan navigasi cepat | **1 lulus**, tiga siklus resize lalu navigasi; sebelumnya gagal dengan tiga error Chart.js |
| Build produksi Vue/Vite | Berhasil pada source akhir |
| Composer validate | Valid |
| Integrasi MySQL lokal | Satu soal dipakai ketiga jenis; hasil 100/100, 100/100 dan 600/600; rata-rata normalisasi 100; sertifikat hanya sertifikasi |
| Perlindungan data MySQL | Transaksi smoke di-rollback; jumlah pengguna, soal, attempt dan sertifikat kembali semula; **0 baris QA tersimpan** |

Bukti akhir lengkap: `tmp/qa/assessment-audit-browser-report.json`. Bukti pendukung: `tmp/qa/mysql-test-types-smoke.json`, `tmp/qa/chart-resize-before-fix-browser-report.json`, dan `tmp/qa/chart-resize-after-fix-browser-report.json`. Tes backend dan browser memakai SQLite terpisah dari MySQL utama. Tidak ada migrasi atau reset database utama pada audit lanjutan ini.

## Penyempurnaan tiga jenis tes - verifikasi sebelumnya

| Pemeriksaan terbaru | Hasil |
| --- | --- |
| Seluruh unit/feature PHP | **91 lulus, 520 assertion** |
| Suite Chrome lengkap | **16 lulus**, 0 gagal/skipped/flaky; 4 file, termasuk preferensi teks, speaking, jenis tes dan alur lama |
| Regresi fixture akhir | **2 lulus** pada build terbaru: deadline soal dan rekam/upload/playback/review speaking dengan override kebijakan eksplisit |
| Build Vue/Vite dan Composer validate | Berhasil |
| Integrasi MySQL lokal | Tiga jenis memakai satu soal; skor 100; sertifikat hanya sertifikasi; transaksi dibatalkan dan jumlah data kembali semula |
| Migrasi lokal | `000400` preferensi teks dan `000500` jenis tes/bank soal diterapkan setelah backup privat |
| Ukuran Normal | Soal 21 px, pilihan 20 px, timer 28 px; mengikuti Large/Extra Large |
| Responsif | Dashboard, public/login/verifikasi, ujian dan diagram diperiksa hingga 320 px; tidak ada overflow dokumen horizontal |
| Audit antarmuka akhir | CTA membuka paket sesuai jenis; label grafik Extra Large 20 px; judul ujian memakai lebar penuh di ponsel; tombol editor dan tautan publik yang terlihat mencapai target 44 px |
| Laporan penempatan | PDF 2 halaman diperiksa dari hasil render; tabel/rekomendasi utuh dan penomoran halaman tersedia |

Bukti terbaru: `tmp/qa/test-types-full-browser-report.json`, `tmp/qa/test-types-final-fixture-browser-report.json`, `tmp/qa/mysql-test-types-smoke.json`, `tmp/qa/final-ui-audit.json`, `tmp/qa/final-target-control-audit.json`, `tmp/qa/public-audit.json`, serta render PDF pada `tmp/pdfs/placement-report-1.png` dan `placement-report-2.png`. Pengujian Chrome hanya memakai SQLite terpisah. Smoke MySQL memakai transaksi dengan rollback dan tidak meninggalkan baris QA. Diagram serta batas adaptive testing/klasifikasi internal dijelaskan pada [panduan jenis tes](test-types.md).

## Catatan verifikasi sebelum penyempurnaan

Tanggal: **23 Oktober 2026**. Lingkungan utama: PHP **8.3.31**, MySQL **8.0.30**, Node.js dan Chrome lokal. Pengujian browser memakai SQLite terpisah; database MySQL utama tetap memiliki **0 attempt pengujian**.

| Pemeriksaan | Hasil |
|---|---|
| Suite unit dan feature PHP | 66 tes, 315 assertion, seluruhnya lolos |
| Build produksi Vue/Vite | Berhasil; font dibundel lokal, aset Vue/chart dipisah |
| Composer validate | Konfigurasi dan lock valid |
| Cache deployment | `config:cache` dan `route:cache` berhasil pada lokasi QA terpisah; cache QA dibersihkan setelah pemeriksaan |
| Audit dependensi Composer | Tidak ada advisori kerentanan yang dilaporkan saat pemeriksaan |
| Audit dependensi npm | 0 kerentanan yang dilaporkan saat pemeriksaan |
| Migrasi MySQL | Seluruh migrasi domain, antrean manual, dan deadline soal sudah diterapkan |
| Chrome: ujian peserta | Autosave/reload, matching/drag-drop, dropdown berkelompok, passage, gambar/video privat, submit dan hasil lolos |
| Chrome: Superadmin | Buat/ubah/versi/duplikasi soal, isian berkelompok, pengaturan dan paket lolos |
| Chrome: penilaian manual | Skor tetap null sebelum review; jawaban kosong nol; review menyelesaikan hasil |
| Chrome: seluler 390 px | Landing, dashboard, daftar dan kesiapan ujian tanpa overflow horizontal |
| Chrome: timer soal | Deadline dua detik tidak berubah saat revisit/reload; soal lain tetap dapat dijawab |
| Chrome: speaking dengan mikrofon simulasi | Rekam 3 detik, upload, recovery setelah reload, akses media pemilik 200/akses setelah logout 401, playback admin, dan review 85 hingga selesai |
| Suite Chrome lengkap | 6 tes dalam 2 file, seluruhnya lolos dalam 261,07 detik (sekitar 4,4 menit) |
| Error JavaScript pada enam alur Chrome | 0 uncaught error; 0 flaky dan 0 skipped |
| QA terfokus layout setelah perbaikan input file | Bank soal 1440/390/320 px, dashboard 390/320 px, dan media tanpa overflow horizontal |
| QA terfokus file chooser dan unduhan | Tombol Import/Upload membuka chooser; template/ekspor XLSX 200 dengan signature PK; laporan dengan filter tanggal akhir saja 200 |
| QA terfokus identitas dan JavaScript | Locale default id dan judul INBAF sesuai; tidak ada uncaught error |
| PDF dan QR | 8 kombinasi template/kertas/orientasi satu halaman; QR dari hasil render berhasil didekode |
| Impor Excel | XLSX nyata divalidasi sebelum transaksi; satu baris invalid membatalkan seluruh impor |
| Hak akses dan integritas | Ownership attempt/media/PDF, RBAC, snapshot kunci dan kebijakan, pencabutan/expiry/tamper sertifikat diuji |

Run final `npm run test:browser` menjalankan **keenam tes dalam satu run** dan seluruhnya lolos. Laporan asli disalin ke `tmp/qa/workflows-report.json` setelah jumlah tes, kegagalan, flaky, dan skipped diperiksa.

Run enam tes tersebut mendahului perbaikan visual dua input file pada heading AdminWorkspace, dari `class="sr-only"` menjadi atribut `hidden`. Sesudah perbaikan ini, build ulang berhasil dan QA terfokus pada layout, file chooser, unduhan, filter laporan, identitas, serta error JavaScript lolos. Bukti terpisah tersedia di `tmp/browser-preview/report.json`, dengan tangkapan layar pada direktori yang sama.

Tes speaking juga sebelumnya dijalankan terpisah dan lolos dalam **55,4 detik**: Chrome memakai perangkat mikrofon simulasi melalui flag pengujian, menghasilkan rekaman MediaRecorder nyata, lalu mengunggah dan memutar hasilnya sebelum penilaian manual diselesaikan. Tes ini tidak menggunakan atau membuktikan fungsi mikrofon fisik.

Perintah yang dapat diulang:

```powershell
php artisan test
npm run build
npm run test:browser
composer validate --no-check-publish
composer audit
npm audit
```

Ikuti README untuk menjalankan server browser **8001** dengan database SQLite khusus. Setup browser menolak config cache dan mengunci reset database ke `tmp/browser.sqlite`. Bukti run lengkap enam tes tersedia di `tmp/qa/workflows-report.json`; bukti run speaking terpisah tersedia di `tmp/qa/speaking-report.json`. `tmp/playwright-results/report.json` mencerminkan perintah Playwright terakhir dan dapat berubah, termasuk saat menjalankan `--list`. Direktori `tmp` diabaikan Git.

Pratinjau PDF terdapat di `tmp/pdfs`, dan tangkapan layar antarmuka di `tmp/browser-preview`. Identitas pada contoh sertifikat bersifat fiktif dan tidak menjadi hasil peserta dalam MySQL utama.

Pengujian ini membuktikan perilaku pada lingkungan lokal tersebut. Mikrofon fisik, browser/perangkat peserta lain, kapasitas serentak, PostgreSQL, pemulihan backup institusi, audit aksesibilitas dan validitas asesmen tetap perlu diperiksa pada lingkungan operasional. Materi listening awal tetap draft sampai rekaman yang sesuai diunggah. AI dan email eksternal belum diaktifkan.
