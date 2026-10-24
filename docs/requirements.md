# Cakupan persyaratan dan bukti

Dokumen ini memetakan spesifikasi INBAF ke implementasi yang dapat diperiksa. Status **tersedia** berarti ada implementasi, bukan sertifikasi produksi. **Terbatas** mengidentifikasi batas yang perlu diketahui institusi sebelum memakai fitur untuk keputusan resmi.

Penyempurnaan 23 Oktober 2026 menambahkan tiga jenis tes independen, izin bank soal, snapshot kebijakan, metode scoring terkonfigurasi, laporan penempatan, dan preferensi ukuran teks tersimpan. [Cakupan dan diagram rinci](test-types.md) menjelaskan batas implementasi: adaptive testing masih arah pengembangan dan A1–C2 tetap klasifikasi internal.

| Area spesifikasi | Implementasi / lokasi | Status dan batas |
| --- | --- | --- |
| Arsitektur, use case, workflow, ERD, RBAC | `docs/architecture.md`, migrations, middleware | Tersedia; roles dibatasi dua nilai aplikasi, tidak memakai paket permission terpisah |
| Laravel/PHP, database, Vue/Tailwind | `composer.lock`, `package-lock.json`, `resources/js` | Tersedia; local MySQL dan pengujian SQLite; PostgreSQL memerlukan smoke test deployment |
| Registrasi, login, profil, akun aktif | `AuthController`, `ParticipantController`, `RequireSuperadmin`, `SecurityHeaders` | Tersedia; registrasi tidak menerima role dari klien; email delivery dan verifikasi email tidak diaktifkan |
| Profil pribadi peserta dan Superadmin, foto, password, logout | `AccountController`, `ProfilePhotoService`, `AccountProfile`, `AccountMenu` | Tersedia; akses milik sendiri, foto privat/crop, preferensi bahasa peserta, sesi lain dibatalkan setelah perubahan password; jawaban dan nama historis sertifikat tetap |
| Dashboard dan riwayat | `ParticipantController`, `Dashboard.vue`, `ParticipantPages.vue` | Memakai database aktual; data awal hanyalah seed yang dapat diedit |
| Tujuh kompetensi dinamis | `competencies`, admin workspace | Tersedia: aktif/nonaktif, urutan dan penggunaan pada sesi |
| Tingkat A1..C2 dan skala | `proficiency_levels`, `ScoringService` | Ambang dinamis pada skala normalisasi 0..100; skala keluaran dapat dikonfigurasi dan disnapshot |
| Bank soal 18 tipe | `question_types`, `questions`, `QuestionRenderer.vue`, admin builder | Definisi tipe, opsi, schema, metadata dan rubric extensible. Stimulus listening/reading/image/video menggunakan grup bersama |
| Versioning dan riwayat historis | `question_versions`, `exam_attempts.snapshot` | Snapshot memisahkan attempt dari bank soal yang dapat berubah; diuji setelah perubahan kunci |
| Builder dan import/export | `AdminController`, `AdminWorkspace.vue` | Editor menyesuaikan tipe, duplicate, bulk, template/import/export CSV dan Excel native `.xlsx` melalui PhpSpreadsheet. Import semua baris divalidasi secara atomik; pengujian mencakup validasi XLSX dan rollback seluruh impor jika satu baris tidak valid |
| Paket, sesi, durasi, bobot, kesempatan | `exam_packages`, `exam_sections`, `AttemptService` | Tersedia, dengan pilihan manual/acak dan periode pada settings. Jadwal bukan tabel terpisah |
| Timer dan sesi | `AttemptService`, `ExamWorkspace.vue` | Waktu server, sesi berurutan, auto-submit dan deadline opsional per soal. Aktivasi pertama tersimpan di server, tidak direset saat kembali, serta dibatasi deadline sesi/attempt |
| Autosave, recovery, submit | `AttemptService`, `ExamWorkspace.vue` | Jawaban per key unik, update atomik, submit idempoten, simpan lokal/sinkronisasi antarmuka; pemulihan jaringan memerlukan uji browser pada perangkat target |
| Monitoring ujian | Audit event, exam workspace | Peristiwa tab/fullscreen/koneksi dicatat; tidak menjamin anti-cheating 100% |
| Listening dan transcript | Grup media dan audio player | Stimulus bersama, private media, kebijakan replay antarmuka. Batas replay bukan DRM dan tidak menghalangi perekaman ulang |
| Reading | Grup passage, exam workspace | Satu bacaan untuk banyak soal; layout desktop dan mobile |
| Writing | Essay response, rubric, manual assessments | Menahan skor final sampai penilaian manual selesai; jawaban kosong bernilai nol |
| Speaking | MediaRecorder, endpoint recordings, media privat | Rekaman disimpan dan dapat dinilai. Izin mikrofon, format codec dan koneksi harus diuji di browser target dengan HTTPS |
| Scoring objektif | `ScoringService::scoreQuestion` | Tepat/set/posisi/teks ternormalisasi dan kredit parsial; diuji untuk urutan, penalti opsi salah dan set tepat |
| Scoring manual | Endpoint admin reviews, `manual_assessments` | Reviewer dan feedback diaudit; penilaian attempt yang sudah final ditolak |
| AI opsional | Settings/provider boundary | Tidak ada provider AI aktif. Mengaktifkan AI tanpa provider ditolak; tidak ada rekomendasi otomatis palsu |
| Grafik hasil | `ScoreChart.vue`, hasil dashboard | Radar/bar/line/donut memakai data aktual; kekuatan, fokus latihan dan perbandingan hasil paket yang sama. Sesi kompetensi berulang diagregasi sesuai bobot; interpretasi skor adalah asesmen internal |
| PDF modern dan formal | `CertificateController`, `resources/views/certificates` | Dua desain asli, nama/nomor/hasil/kompetensi/lembaga/signer/tanggal/QR. Seluruh 8 kombinasi modern/formal × A4/Letter × landscape/portrait dirender satu halaman dan QR dipindai otomatis |
| Kebijakan sertifikat | Snapshot `certificate_policy`, `CertificateService` | Template, kertas, orientasi, masa berlaku, signer dan identitas historis; background cover transparan, logo dan gambar signature privat. Path dan hash SHA-256 gambar terikat HMAC. Editor drag elemen secara bebas belum tersedia |
| Verifikasi dan pencabutan | `/api/verify/{token}`, revoke endpoint | Token acak 256 bit, HMAC SHA-256, valid/expired/revoked/not_found, pencabutan diaudit, informasi pribadi dibatasi |
| Branding dan aset asli | `public/brand` | SVG original, varian terang/gelap, favicon dan mark cetak. Konfigurasi logo digunakan sesuai integrasi media |
| ID/EN dan tampilan | Translation modules, aplikasi Vue | Pilihan bahasa, tema, responsive dan reduced motion; pemeriksaan kelengkapan terjemahan serta audit WCAG pada alur penuh diperlukan |
| Validasi, proteksi dan audit | Laravel validation, CSRF, RBAC, private media, rate limits | Uji role, ownership, key leakage, certificate integrity. Tidak menggantikan penetration test eksternal |
| Queue/scheduler | `FinalizeExpiredAttempt`, `inbaf:expire-attempts` | Expiry terjadwal melalui queue; worker dan scheduler harus dijalankan operator |
| Backup/restore | `inbaf:backup`, `docs/deployment.md` | Backup database privat tersedia; media, enkripsi, retensi, offsite dan latihan restore perlu dioperasikan institusi |
| Retensi privasi | Pengaturan retensi dan dokumentasi operasi | Kebijakan perlu disepakati institusi; tidak ada penghapusan otomatis rekaman secara menyeluruh |
| Pengujian dan produksi | `tests/Feature/AssessmentFlowTest.php`, deployment checklist | Bukti otomatis di bawah; kapasitas serentak, akreditasi, aksesibilitas dan operasi produksi belum disertifikasi |

## Bukti otomatis

Jalankan dari root proyek dengan PHP yang memiliki driver SQLite, DOM, mbstring dan GD:

```sh
php artisan test
npm run build
```

Suite `AssessmentFlowTest` mencakup:

- Registrasi dengan injeksi role tetap menjadi peserta, admin ditolak dan akun nonaktif tidak dapat login/mengakses data.
- Attempt milik peserta lain dan key soal di luar snapshot ditolak.
- API peserta tidak memuat `answer_schema` atau kunci jawaban.
- Perubahan kunci bank soal setelah attempt dimulai tidak mengubah skor historis.
- Batas kesempatan, pengiriman berulang dan jumlah sertifikat/jawaban tetap konsisten.
- Waktu server menutup attempt kedaluwarsa dan menolak penyimpanan susulan.
- Jawaban esai menunggu review, skor belum final, sertifikat belum terbit; review admin menyelesaikan hasil dan sertifikat.
- Penilaian ulang setelah final ditolak agar hasil dan sertifikat tidak berselisih.
- Penilaian multiple response tepat/parsial/penalti, normalisasi teks, true/false dan ordering.
- Skala nilai tersimpan pada snapshot; kelayakan sertifikat memakai skor normalisasi, bukan angka keluaran yang sudah diskalakan.
- Penerbitan sertifikat idempoten dan identitas historis tidak mengikuti perubahan profil/paket.
- Verifikasi publik membatasi informasi, menolak payload yang diubah, serta membedakan expired dan revoked.
- Pencabutan memerlukan role admin dan alasan; jejak audit tersedia.
- Download menghasilkan PDF nyata dengan image QR dan tautan domain deployment, serta memeriksa pemilik.
- Konfigurasi paper/orientation/template dan asset diambil pada saat attempt dimulai; perubahan pengaturan sesudahnya tidak mengubah sertifikat. Penggantian byte gambar signature ditolak dan path traversal, remote URL serta SVG upload tidak diterima sebagai asset sertifikat.

Suite `QuestionTimerTest` memeriksa tiga alur terfokus: aktivasi hanya untuk pemilik, durasi snapshot tetap berlaku setelah soal diubah, revisit tidak memperpanjang deadline, batas waktu tepat menolak perubahan dan mempertahankan jawaban terakhir, sesi mendatang belum dapat diaktifkan, deadline tidak melewati sesi/attempt, serta durasi null dan aktivasi otomatis pada save pertama. Respons kedaluwarsa memiliki kode `question_expired` agar antrean autosave dapat melewati satu jawaban yang sudah ditutup.

QA visual tambahan merender semua delapan kombinasi template, ukuran kertas dan orientasi menjadi PNG, dengan background dan signature contoh. Setiap output diperiksa satu halaman, teks wajib, dimensi halaman dan QR dari hasil render ke URL verifikasi. Ini menguji hasil PDF nyata, bukan hanya keberadaan library QR. Contoh QA menggunakan identitas dan signature fiktif di direktori sementara, tidak dimasukkan sebagai sertifikat peserta produksi.

## Validasi institusi sebelum penggunaan resmi

Lakukan uji mikrofon manusia, perangkat mobile, koneksi lemah, screen reader, scan QR cetak dan alur ujian penuh dengan materi institusi. Lakukan load test pada database target, uji submit bersamaan, latihan restore dan review keamanan. Tentukan serta dokumentasikan standardisasi materi, validitas konstruk, reliabilitas, ambang kemampuan dan prosedur reviewer. Klaim kesetaraan eksternal tidak boleh dibuat hanya berdasarkan rentang A1..C2 di aplikasi.
