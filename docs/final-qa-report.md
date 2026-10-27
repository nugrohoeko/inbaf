# INBAF — laporan final quality assurance

Tanggal audit: **24–25 Oktober 2026**. Kandidat yang diperiksa: working tree lokal sesudah `6b3609152faadb6e7001323555b5c464dd241a6f`. Perbaikan audit belum diterbitkan sebagai release atau dideploy.

**Keputusan: NOT READY FOR DEPLOYMENT.** Seluruh 402 tes otomatis unik yang dilaporkan di bawah telah lulus pada eksekusi terakhirnya. Runtime lokal tetap memerlukan pembaruan keamanan; konfigurasi dan operasi pada infrastruktur production belum dibuktikan. Hasil lokal tidak dapat menggantikan pemeriksaan tersebut.

## A. Ringkasan audit

Inventaris mencakup **22 modul operasional**, **26 keluarga halaman kanonis**, **60 definisi route aplikasi** (59 API dan satu shell SPA), 13 controller aplikasi, 20 model, lima service, 11 migration, serta 25 komponen Vue ditambah `App.vue`. Tiga route framework health/storage menjadikan total 63 definisi; perilakunya dicatat pada inventaris route. Alias profil/password dan variasi role tidak dihitung sebagai halaman tambahan.

| Kelompok bukti | Jumlah dan hasil akhir | Lingkup |
|---|---|---|
| PHPUnit unit/feature | 309 PASS, 0 FAIL/error/skip; 2.426 assertion | SQLite memory, 49,904 detik; referensi scoring, policy, authorization, transaksi dan regresi aplikasi |
| Frontend Node | 17 PASS, 0 FAIL | Store, preferensi dan editor kebijakan/rubrik |
| Browser Chromium | 76 PASS, 0 FAIL/skip pada hasil terakhir per kasus | 76 skenario unik pada 21 spec; dua role, publik, alur nyata dan matriks viewport |
| MySQL operasional | 19/19 PASS | Backup/restore, multiproses, rollback, worker dan arsip berulang |
| Registrasi bersamaan | 2/2 pemeriksaan PASS | Sepuluh proses; 1×201 dan 9×422; satu user/profile/audit |
| Cache/route/view release | 7/7 PASS | Tujuh perintah pada salinan QA; cache pengembang terpisah |
| PDF dan QR aktual | 8/8 PASS | Dua template × dua ukuran × dua orientasi; parse/render, skor 92.55 dan decode QR dari PDF |
| Integritas database lokal | 82/82 PASS pada masing-masing audit utama, restore dan pasca-concurrency | Pemeriksaan hanya baca; data historis utama masih kosong |
| Dependency | PASS | Composer dan npm: nol advisory yang dilaporkan saat audit |
| Preflight production lokal | 33 PASS, 9 FAIL, 8 NOT TESTED | 50 pemeriksaan; ini konfigurasi development, bukan server production |

Angka tes otomatis dihitung dari kasus unik, bukan jumlah percobaan atau assertion. Pemeriksaan operasional/integritas/PDF dilaporkan terpisah agar tidak menggandakan skenario. Hasil run awal dan retry disimpan; hanya eksekusi terakhir setiap kasus menjadi hasil akhir. Tidak ada klaim bahwa seluruh jenis perangkat atau semua kemungkinan input telah diuji.

Jumlah skenario otomatis unik: **402 = 309 backend + 17 frontend + 76 browser; 402 lulus, 0 gagal, 0 skip pada hasil terakhir**. Terdapat **21 cacat aplikasi ditemukan dan diperbaiki**, seluruhnya diuji ulang: DS-01–10, FSEC-01–04, FUI-01–02, dan QL-01–05. Tidak ada cacat aplikasi yang diketahui belum diperbaiki dalam cakupan lokal ini. **Empat temuan runtime/lingkungan FSEC-05–08 tetap terbuka** dan menghalangi deployment, sehingga total register adalah 25 temuan (21 ditutup, empat terbuka). Rincian akar masalah dan sumber bukti ada pada bagian C dan E.

## B. Hasil pengujian per modul

Status berikut merujuk pada bukti lokal yang telah dieksekusi; kelulusan modul tidak menggantikan pemeriksaan infrastruktur production pada bagian E–F.

| Modul | Role | Fungsional | Integrasi | Keamanan | Status |
|---|---|---|---|---|---|
| Authentication | Semua | Register/login/logout, reset/aktivasi, invalid/expired link, password reveal | Sesi nyata dan registrasi bersamaan | CSRF, throttle, hash/byte boundary, akun nonaktif | PASS |
| User management | Superadmin | Create/edit/detail/photo, role, status, soft delete, search/sort/page, reset | Retensi riwayat dan pencabutan sesi | Self/last-admin protection, role confirmation, validasi | PASS |
| Peserta legacy | Superadmin | Create/edit/deactivate dan status pending | User/profile konsisten | Validasi whitelist dan akses admin | PASS |
| Profil | Kedua role | Detail, edit/cancel, crop/replace/remove photo, password, preferensi | Avatar lintas halaman dan bahasa perangkat berikutnya | Owner scope; password baru mencabut sesi lain | PASS |
| Dashboard | Kedua role | KPI, filter, chart/tabel dan navigasi | Nilai/count dari database; filter cepat dan teardown | Data admin/peserta terpisah | PASS |
| Bank soal | Superadmin | 18 tipe, create/edit/duplicate/archive/version, preview, bulk, impor/ekspor | Snapshot lama tetap; CSV/XLSX dan validasi atomik | Answer schema, batas penggunaan dan izin jenis tes | PASS |
| Grup/passage | Superadmin | Create/edit, hapus grup kosong; grup terpakai ditolak | Referensi dikunci saat CRUD/import/duplicate | ID scalar dan recheck race | PASS |
| Media | Superadmin/peserta berizin | Unggah PNG/WAV, putar gambar/audio/video starter | Streaming privat dan respons rekaman speaking | MIME/size/raster validation dan akses pemilik | PASS |
| Kompetensi | Superadmin | Create/edit/archive, kode/nama/status/urutan | Paket, dashboard dan atlas | Validasi unik dan akses admin | PASS |
| Level kemahiran | Superadmin/publik | Edit threshold/deskripsi; atlas bilingual | Batas internal A1–C2 dan hasil | Validasi threshold/order | PASS |
| Paket ujian | Superadmin | Manual/random, section, durasi/bobot/jadwal/akses | Soal, policy dan snapshot | Attempts limit, confidentiality, availability | PASS |
| Pengaturan/policy | Superadmin | Enam tab dan save/persist | Tiga jenis tes memiliki override independen | Validasi server/allowlist | PASS |
| Simulation/Practice | Peserta | Start/resume/answer/submit dan riwayat | Untimed sesuai policy; hasil terpisah | Tidak menerbitkan sertifikat certification | PASS |
| Placement | Peserta | Ujian lengkap, level/rekomendasi dan laporan cetak | Skor/detail/dashboard/PDF konsisten | Owner scope; tidak menerbitkan sertifikat certification | PASS |
| Certification | Peserta/admin penilai | Jawaban otomatis/manual, pending/final, penerbitan | Hasil final → PDF → QR → verifikasi/revoke | Eligibility dan snapshot; sekali secara logis | PASS |
| Engine dan recovery | Peserta | 18 tipe respons; nomor/next/previous/review mark, timer, reload/offline/reauth/dua tab | Autosave nyata; deadline server; submit berulang | Owner/status checks; konflik ditangani | PASS |
| Manual review | Superadmin | Essay/speaking, playback, kriteria/feedback | Total berbobot dihitung server; antrean selesai | Rubrik snapshot; replay identik/conflict; parent lock | PASS |
| Scoring | Terkait | Benar/salah/kosong, partial, bobot, skala, pembulatan | Tiga agregasi dan hasil resmi konsisten | Finalisasi beku, rollback bila konfigurasi gagal | PASS |
| Hasil/riwayat | Peserta/admin berizin | Pending dibedakan dari final, chart/detail/rekomendasi | Jenis tes terpisah, Back dan respons tertunda | Tidak menampilkan kunci soal rahasia | PASS |
| Sertifikat/verifikasi | Pemilik/admin/publik | Download, valid/expired/revoked/not-found, pencabutan | Delapan PDF/QR nyata; identitas historis tetap | HMAC, token acak, owner scope, penerbitan unik | PASS |
| Notifikasi aktivitas | Kedua role | Feed nyata, loading/empty/error-retry, resume/result links | Endpoint privat, hitungan review dan aktivitas pemilik | Whitelist payload; no-store; tanpa token/jawaban | PASS |
| Audit log | Superadmin | Search/page/detail/dismissal | Event kritis dan jumlah replay diuji | Tidak mengekspor password/token; akses admin | PASS |

Peta tindakan per halaman: [inventaris UI](final-ui-audit.md). Peta endpoint/metode/controller: [inventaris route](final-route-inventory.md). Tidak ada tombol generate sertifikat manual atau CRUD media lengkap yang diklaim: penerbitan mengikuti finalisasi dan kelayakan hasil. Feed notifikasi dimuat saat panel dibuka; pengiriman email diuji terpisah dari feed.

## C. Daftar bug dan perbaikannya

| ID | Modul/severity | Masalah dan akar penyebab | File/perbaikan | Status/uji ulang |
|---|---|---|---|---|
| DS-01 | Scoring / High | Pembulatan persentase section sebelum agregasi/konversi membuat 44.44 menjadi 44.45 | `ScoringService.php`: presisi penuh sampai nilai keluaran; fixture weighted/equal competency menjadi 44.44 atau 266.67 pada skala 600 | Tiga metode diuji; weighted questions pada fixture yang sama menghasilkan 50/300; PASS |
| DS-02 | Manual / High | Overall dari klien dapat bertentangan dengan rubrik; kriteria tidak lengkap/berbobot invalid | Service menghitung dari rubrik snapshot; controller/UI mewajibkan kriteria dan overall readonly | Referensi 90×3 + 70×1 = 85; invalid rubrik; PASS |
| DS-03 | Manual / High | Replay dapat menambah assessment atau mengubah nilai yang sudah dinilai | Parent/answer lock, replay identik mengembalikan hasil, konflik 409 | Tanpa duplikasi assessment/audit; PASS |
| DS-04 | Finalisasi / High | Panggilan ulang dapat menulis ulang hasil final dan audit | `ScoringService`: completed result tetap | Hasil/sertifikat/audit tidak berubah; PASS |
| DS-05 | Start / High | Account lock diambil tetapi objek user lama tetap digunakan | `AttemptService`: gunakan row user terbaru | Akun dinonaktifkan ditolak tanpa usage/attempt; PASS |
| DS-06 | PDF / Medium | Template menampilkan satu desimal untuk hasil dua desimal | Kedua Blade certificate menampilkan dua desimal | Delapan PDF menampilkan 92.55; PASS |
| DS-07 | MySQL start / High | `firstOrCreate` membaca snapshot lama setelah menunggu lock soal | Current locking read untuk versi soal | Kontensi batas penggunaan; PASS |
| DS-08 | MySQL start / High | Lock pencarian attempt yang belum ada menimbulkan gap-lock cycle | Cari ID tanpa lock, lalu PK lock di bawah serialisasi user | Sepuluh start satu attempt; PASS |
| DS-09 | MySQL manual / High | Snapshot transaksi dibuat sebelum menunggu parent lock | Pointer read di luar transaksi, parent dahulu, assessment current read, retry deadlock | Sepuluh proses/request dengan satu akun reviewer termasuk replay; 12 assessment/6 hasil; PASS |
| DS-10 | Editor rubrik / Medium | Format rubrik string list lama tidak dinormalisasi di editor | `AdminWorkspace.vue`: normalisasi bersama format list/map/object | Regresi frontend dan review; PASS |
| FSEC-01 | Password / Medium | Panjang Unicode/ASCII melampaui 72 byte bcrypt | Shared `BcryptPassword` rule seluruh jalur tulis | Boundary/hash regression; PASS |
| FSEC-02 | Password / Medium | NUL byte menyebabkan exception hashing | Tolak sebelum hashing; validasi 422 | Lima jalur, token/hash tetap; PASS |
| FSEC-03 | Rich text / High | Denylist URL melewatkan executable URL tersamarkan/namespace asing | Sanitizer allowlist tag/atribut/protokol dan gambar aman | Browser XSS payload dan safe-format preservation; PASS |
| FSEC-04 | Register / Medium | Race lolos unique validation lalu constraint menghasilkan 500 | Konfirmasi competing email dan ubah menjadi 422 sesudah rollback | Sepuluh proses: 1×201 + 9×422; PASS |
| FUI-01 | Upload / Low | UI menawarkan PDF yang ditolak whitelist server | Sesuaikan accept dan teks gambar/audio/video | PNG/WAV nyata; PASS |
| FUI-02 | Notifikasi / Medium | Panel statis selalu kosong | Endpoint scoped + `NotificationPanel.vue` | Empat feature dan satu browser; PASS |
| QL-01 | Grup / High | Delete melepaskan passage dari soal karena FK nullOnDelete | Tolak grup terpakai, parent lock, konfirmasi delete permanen grup kosong | Lifecycle/race + browser admin; PASS |
| QL-02 | Arsip / Medium | Replay/concurrency dapat membuat versi/audit berulang | Reload dan lock di transaksi, no-op bila sudah archived | Feature dan sepuluh proses archive; PASS |
| QL-03 | Referensi grup / Medium | Grup hilang setelah validasi; duplicate memakai sumber lama | Lock Group → Question, import seluruh parent dahulu, recheck 422/409 | Lima race/reload cases; PASS |
| QL-04 | Validasi grup / Medium | Array ID bisa melewati exists lalu salah tipe pada penyimpanan | Explicit integer rule | 422 tanpa question/version; PASS |
| QL-05 | Schema jawaban / High | Bentuk nested/string salah mencapai typed scoring atau hasil nol | Validasi bentuk saat CRUD/import/activate/score; invalid snapshot membatalkan transaksi | 22 kasus invalid/legacy/rollback; PASS |

Detail referensi hitungan dan PDF ada di [audit domain](final-domain-audit.md); temuan keamanan dan lifecycle di [audit security](final-security-audit.md). Kegagalan harness (fixture audio belum lengkap, tes lama mengisi overall readonly, dan anggaran waktu keseluruhan tes panjang) dilaporkan sebagai koreksi tes, tidak menambah angka cacat aplikasi. Assertion dan timeout setiap aksi tetap memeriksa perilaku yang sama. Atlas mengalami satu timeout kestabilan elemen pada run awal; retry dengan source dan assertion yang sama lulus dalam 20,8 detik. Kejadian intermiten ini disimpan sebagai observasi kestabilan tes, bukan dihitung sebagai bug aplikasi yang telah diperbaiki. Pemeriksaan storage juga dikoreksi untuk membaca redirect login asli, karena mengikuti redirect menghasilkan status halaman login 200. Pemeriksaan akhirnya membuktikan health 200, GET anonim diarahkan ke login, serta PUT tanpa signature ditolak. Sanitizer tetap melewati seluruh assertion.

## D. Hasil audit database, integrasi dan keandalan

Audit utama hanya membaca schema/data pada database lokal. Pemeriksaan meliputi seluruh FK, unique index yang ditemukan, constraint domain wajib, kecocokan answer/snapshot/usage, tipe tes, final state, rekonsiliasi skor, assessment lintas attempt, counter pemakaian, owner/hasil/signature sertifikat, dan duplicate active attempts. Temuan diuji negatif melalui data sintetis agar audit tidak sekadar memeriksa database kosong.

Database utama memiliki 32 tabel, 20 FK, 44 unique index dan 11 migration. Riwayat attempt/sertifikat utamanya kosong saat audit; karena itu bukti sejarah, sertifikat dan concurrency berasal dari fixture serta database QA yang dipulihkan. Tidak ada seed/reset/repair/penghapusan data utama. Hasil final: **82/82 PASS** pada masing-masing audit utama, restore, dan pasca-concurrency.

Latihan operasional benar-benar membuat backup privat MySQL, restore ke database baru, membandingkan fingerprint 12 tabel domain, memulihkan dua asset media terdaftar dengan hash yang sama, menjalankan migration di salinan, lalu memeriksa integritas. Dua puluh peserta sintetis menyelesaikan jalur otomatis; sepuluh proses dipakai pada fase kontensi. Uji mencakup:

- Sepuluh start untuk pengguna/paket sama menghasilkan satu attempt, satu set jawaban dan satu audit.
- Save/submit/issue berulang menjaga keunikan; dua puluh hasil bernilai referensi 100 dan sertifikat sesuai kebijakan.
- Sepuluh pengguna bersaing atas batas tiga pemakaian soal; hanya tiga start diperbolehkan.
- Enam attempt manual dengan dua essay masing-masing serta sepuluh proses/request memakai satu akun reviewer termasuk replay menghasilkan tepat 12 assessment, enam hasil 80 dan enam sertifikat.
- Exception yang diinjeksi setelah reservasi soal membatalkan seluruh write/counter transaksi.
- Worker database benar-benar memproses dua job expiry yang sama, dengan satu finalisasi logis.
- Sepuluh archive request menghasilkan satu kenaikan versi dan satu audit.
- Fingerprint domain utama dibandingkan kembali setelah seluruh latihan.

Hasil final: **19/19 PASS**, `tmp/qa/final/operations/report.json`. Fingerprint domain utama tetap sama. DB QA serta backup privat dipertahankan; tidak otomatis dihapus. Latihan ini tidak membuktikan pemulihan seluruh rahasia, offsite backup, target RPO/RTO atau storage hosting. Prosedur release/rollback tersedia di [release runbook](release-runbook.md). Koreksi hasil historis/reissue membutuhkan prosedur resmi tersendiri; audit ini tidak menghitung ulang hasil completed.

### Pengukuran performa lokal

HTTP berikut berasal dari request nyata pada skenario final yang lulus. Hanya sampel 2xx dari eksekusi terakhir setiap skenario digunakan; p50/p95 memakai nearest rank. Pengukuran mencakup transport dan penantian pada PHP development server lokal. Sampel sangat kecil pada beberapa operasi; p95 satu sampel sama dengan sampel itu dan bukan estimasi populasi yang kuat.

| Operasi HTTP | N | p50 ms | p95 ms |
|---|---:|---:|---:|
| GET dashboard | 20 | 1302.36 | 1611.02 |
| GET bank soal | 10 | 944.88 | 4715.05 |
| POST bank soal | 7 | 1532.53 | 3357.21 |
| PUT simpan jawaban | 23 | 1265.29 | 1735.73 |
| POST submit | 5 | 1543.82 | 2415.68 |
| POST nilai manual | 1 | 1215.34 | 1215.34 |
| GET PDF sertifikat | 1 | 2909.07 | 2909.07 |
| POST unggah media | 2 | 943.48 | 1100.28 |
| GET daftar media | 5 | 1130.23 | 6386.94 |
| GET stream media | 5 | 2436.61 | 3865.00 |
| POST impor soal | 1 | 1493.38 | 1493.38 |

Service/controller multiproses MySQL berikut mengecualikan transport HTTP dan bootstrap PHP sebelum barrier. Dua puluh peserta dijalankan dalam batch sepuluh proses; ini pilot lokal.

| Operasi kontensi | N | p50 ms | p95 ms |
|---|---:|---:|---:|
| Start pengguna yang sama | 10 | 894.82 | 1303.49 |
| Simpan dua jawaban | 20 | 410.40 | 503.53 |
| Submit, score dan issue | 20 | 357.34 | 367.38 |
| Penilaian manual/replay | 10 | 1667.67 | 1850.91 |

Backup SQL memerlukan **2902.70 ms**, restore SQL **3228.60 ms**. Ini belum waktu disaster recovery lengkap.

Probe query terpisah memakai transaksi read-only pada salinan dengan 26 user, 28 soal, tujuh paket, 30 attempt, 57 jawaban dan 26 sertifikat. Setiap operasi menjalankan satu warm-up yang dikeluarkan, kemudian lima sampel. Durasi wall mencakup serialisasi controller; SQL menggunakan timer Laravel. Cursor dapat diproses setelah timer SQL berhenti.

| Controller | N | Wall p50/p95 ms | Jumlah query | SQL total p50/p95 ms | Query terlama p95 ms |
|---|---:|---:|---:|---:|---:|
| Dashboard admin | 5 | 191.73 / 201.69 | 49 | 73.87 / 75.91 | 5.58 |
| Dashboard peserta | 5 | 143.14 / 144.86 | 40 | 51.66 / 55.11 | 3.36 |
| Bank soal halaman pertama | 5 | 33.56 / 38.79 | 4 | 6.32 / 7.44 | 2.93 |

Tail latency HTTP hingga beberapa detik dan 49 query dashboard admin tetap menjadi baseline untuk profiling pada dataset/hosting sasaran. Tidak ada SLA, kapasitas, LCP atau Core Web Vitals production yang dinyatakan lulus: spesifikasi hosting, dataset representatif, target peserta serentak dan ambang penerimaan belum tersedia. SQL/controller/HTTP bukan lapisan pengukuran yang sama dan tidak dijumlahkan.

Build production berhasil dalam **25,55 detik**. JavaScript aplikasi **437,82 kB / 130,52 kB gzip**, chunk Vue 73,32/28,91 kB dan chart 206,74/70,78 kB. Browser QA menyajikan aset hasil build; `public/hot` dan dev server pengguna dipertahankan. Tidak ada dropdown ukuran font baru yang ditambahkan. Matriks Chrome emulasi memeriksa tujuh lebar 320–1920 piksel, kedua tema, overflow, tabel, dialog, drawer, keyboard/focus dan chart. Perangkat fisik, Safari/Firefox dan audit WCAG menyeluruh belum dibuktikan.

## E. Hasil security audit

Regresi mencakup autentikasi, CSRF/sesi database, RBAC dan IDOR attempt/hasil/PDF/rekaman/foto, soft delete dan pencabutan sesi, password hash/validation, SQL binding/sort allowlist, mass-assignment whitelist, XSS, upload MIME/raster, endpoint privat, redirect internal, token aktivasi/reset sekali pakai, signature sertifikat dan security headers. Server-side authorization tetap menjadi sumber keputusan. Tidak dilakukan klaim sertifikasi OWASP atau penetration test independen.

Composer dan npm melaporkan **nol advisory** pada lock/dependency tree saat audit. Pemindaian ini tidak mencakup patch runtime OS/web server. Pemeriksaan kebocoran membandingkan tiga nilai rahasia terkonfigurasi minimal delapan karakter dengan 237 berkas source/config/test/docs/brand Git-visible; nol berkas cocok. Nilainya tidak diekspor. `.env` tetap ignored; laporan tidak memuat kredensial, token verifikasi atau data pribadi.

| Temuan terbuka | Severity/dampak | Observasi dan tindakan |
|---|---|---|
| FSEC-05: PHP | Critical | CLI lokal 8.3.31, belum baseline security 8.3.36 pada 21 Oktober 2026. Provision runtime yang sudah ditambal atau bukti backport vendor; periksa binary web server, lalu ulangi regresi upload/PDF/aplikasi. |
| FSEC-06: Node | High | Build runtime lokal 22.15.0, sebelum rilis keamanan 22.23.4. Patch build/CI dan rebuild. Aplikasi production menyajikan aset terkompilasi melalui PHP. |
| FSEC-07: MySQL | High | Lokal 8.0.30; lini 8.0 sudah masuk Sustaining Support. Tentukan engine production yang dipelihara atau bukti dukungan vendor; rehearse restore/migration/concurrency di engine tersebut. |
| FSEC-08: target production | Blocker | Target domain/hosting belum tersedia. Local debug/HTTP/mail log/hot marker sengaja dipertahankan. Validasi konfigurasi terpisah, TLS, secure cookie/proxy, email nyata, storage, worker/scheduler, monitoring, kapasitas dan recovery. |

Sumber primer: [PHP advisory 21 Oktober](https://www.php.net/archive/2026.php#2026-10-21-5), [Node security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases), [MySQL lifecycle](https://www.mysql.com/support/eol-notice.html), dan [MySQL 8.0 release notes](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/). Tidak ada runtime global atau konfigurasi developer yang diubah oleh audit.

## F. Deployment checklist

| Komponen | Status | Bukti / tindakan yang masih diperlukan |
|---|---|---|
| Regresi aplikasi dan alur inti | PASS | 309 backend, 17 frontend dan 76 browser unik lulus pada hasil terakhir |
| Akurasi scoring, PDF/QR dan historis | PASS | Referensi scoring, regresi historis dan delapan PDF/QR aktual lulus |
| Constraint, restore, transaksi dan concurrency lokal | PASS | Salinan MySQL; 19 pemeriksaan akhir lulus |
| Dependency advisory lockfile | PASS | Composer/npm nol advisory saat audit; scan ulang sebelum release |
| PHP security baseline | FAIL | Patch runtime CLI dan web |
| Node build security baseline | FAIL | Patch CI/build runtime dan rebuild |
| MySQL maintenance baseline | FAIL | Tentukan target engine yang dipelihara |
| Immutable release/tag dan checksum | NOT TESTED | Perbaikan masih working tree lokal; pin artifact yang benar-benar diuji |
| Config/route/view cache build | PASS | Tujuh perintah pada salinan QA; cache live tetap |
| APP_ENV/debug/HTTPS/cookie/mail/hot marker production | FAIL | Pada konfigurasi lokal: 33 PASS / 9 FAIL / 8 NOT TESTED; target production belum tersedia. Jalankan preflight pada release target tanpa mengubah environment developer |
| Domain, TLS, proxy, document root `public/`, blok `.env` | NOT TESTED | Smoke dari luar target server termasuk error page dan header |
| Persistence storage dan permissions hosting | NOT TESTED | Buktikan media/foto/rekaman/sertifikat bertahan lintas release |
| Email aktivasi/reset terkirim | NOT TESTED | Mailer lokal log; perlu transport nyata, penerimaan dan invalid/expired link |
| Worker supervisor dan scheduler hosting | NOT TESTED | Queue lokal nyata PASS tidak membuktikan service hosting hidup |
| Backup/restore lokal | PASS | SQL dan dua media terdaftar dipulihkan pada salinan |
| Backup offsite/enkripsi/rahasia/RPO/RTO | NOT TESTED | Uji restore lengkap termasuk key penandatangan dan seluruh private storage |
| Rollback prosedural | PASS | Dokumen runbook code-first tersedia; eksekusi rollback target belum diuji |
| Monitoring/log rotation/alert dan failed jobs | NOT TESTED | Konfigurasikan dan buktikan alert target |
| Capacity/SLA dan load target | NOT TESTED | Pilot 20 peserta/10 proses lokal; spesifikasi dan ambang production belum tersedia |
| Browser/perangkat nyata lintas vendor | NOT TESTED | Chromium emulasi viewport tersedia; Safari/Firefox/mobile fisik/screen reader belum diuji |
| API key/token eksternal pihak ketiga | NOT APPLICABLE | Tidak ada integrasi eksternal tersebut yang diwajibkan oleh source yang diaudit |
| Production deployment | NOT TESTED | Tidak dijalankan pada audit ini |

## G. Kesimpulan akhir

**NOT READY FOR DEPLOYMENT.** Kandidat lokal menerima perbaikan pada penyebab masalah dan bukti eksekusi nyata, tetapi rilis production menunggu patch runtime, penetapan serta validasi target hosting, dan bukti operasi/kapasitas/recovery. Regresi lokal selesai: 309 backend, 17 frontend, 76 browser, build production, 19 operasi MySQL, dua pemeriksaan registrasi bersamaan, tujuh cache checks, delapan PDF/QR, dan 82 pemeriksaan integritas per titik audit lulus. Hasil browser merupakan gabungan hasil terakhir per kasus dari run terkoordinasi, bukan klaim satu run bersih tanpa retry. Tidak ada klaim aplikasi bebas bug secara absolut.

Artefak mentah berada pada `tmp/qa/final/` dan `tmp/qa/certificate-artifacts/`, diabaikan Git karena dapat memuat salinan data lokal serta file privat. Ringkasan aman dapat dibagikan melalui dokumen ini. Reproduksi probe dijelaskan di [tests/Operations/README.md](../tests/Operations/README.md). Jangan mengirim backup, manifest worker, PDF bertoken atau log privat ke repository publik.
