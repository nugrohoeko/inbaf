# Audit UI superadmin INBAF

## Inventaris modul aktual

Halaman superadmin menggunakan satu komponen `AdminWorkspace.vue` untuk sumber daya berikut. Modul yang berbagi alur ditempatkan dalam editor yang sudah ada; tidak ada halaman duplikat yang ditambahkan.

| Halaman | Fitur dan subbagian yang diperiksa pada sumber |
| --- | --- |
| `/admin/questions` | Pencarian/filter kompetensi, tipe, status, jenis tes; pilihan massal; impor/ekspor/template; duplikasi; arsip; versi dan riwayat penggunaan. |
| Question builder | Semua jenis soal dari bootstrap backend; pilihan tunggal/ganda, benar/salah, isian/dropdown, pencocokan/drag-drop, pengurutan, jawaban pendek, writing/speaking, listening/gambar/video/percakapan. Rich editor, instruksi, bobot, batas waktu, izin jenis tes, kerahasiaan, batas penggunaan, kunci jawaban, rubrik, batas audio/rekaman, media dan preview. |
| `/admin/packages` | Paket practice/simulation, placement, certification; jadwal mulai/selesai, kode akses, publikasi, sertifikat, pemilihan soal, kebijakan turunan/override, ambang level, durasi/bobot/jumlah soal, kesulitan, pengurutan bagian dan pemilihan soal manual. |
| `/admin/participants` | Daftar peserta, data profil, negara, email, status, tanggal bergabung; editor akun dan kata sandi awal/pengganti. |
| `/admin/competencies` | Kode, nama bilingual, ikon, warna, urutan dan status kompetensi. |
| `/admin/groups` | Kelompok reading, listening/audio, gambar dan video; shared content, rich editor dan media. |
| `/admin/media` | Upload gambar/audio/video/PDF; jenis, ukuran, tanggal dan pembukaan media. |
| `/admin/reviews` | Peserta, soal, jenis, respons, status; tampilan jawaban, rubrik berbobot, nilai keseluruhan dan feedback. |
| `/admin/certificates` | Nomor, peserta, skor, status dan tanggal; unduh PDF, verifikasi publik dan pencabutan. |
| `/admin/levels` | Nama/kode level, skor minimum, urutan dan deskripsi. |
| `/admin/audit` | Peristiwa, pengguna, subjek, tanggal dan dialog detail payload. |
| `/admin/settings` | Identity, Branding, Assessment, Certificates, Security, Notifications; tautan ambang proficiency; kebijakan terpisah practice/placement/certification dan rekomendasi setiap level. |

Overview, analitik, profil, foto, kata sandi, header dan navigasi superadmin diperiksa/diperbaiki oleh pekerjaan komponen lain. Pengujian responsif superadmin juga mencakup `/dashboard` dan `/profile` untuk memeriksa integrasinya.

## Masalah dan perbaikan

- Primitif CSS dasar tabel, formulir, modal dan toolbar tidak ditemukan pada styles awal. Perbaikan global ditangani pada design system bersama; komponen ini menambahkan aturan khusus untuk ruang kerja admin.
- Toolbar dengan banyak filter dapat melebar, nav settings hanya memiliki pola gulir mendatar, dan form dua kolom tidak memiliki batas `min-width` yang konsisten. Kini toolbar membungkus, filter menjadi satu kolom di mobile, settings memakai grid adaptif, dan semua kolom editor dapat menyusut.
- Baris opsi/matching dan rubrik mempunyai banyak kontrol dalam satu baris. Layout mobile kini memakai grid untuk teks, tanda pilihan, kunci jawaban, target pasangan dan tombol hapus; seluruh data serta kontrol tetap ada.
- Tabel mempertahankan semua kolom asli, memiliki caption, region yang dapat difokuskan keyboard, petunjuk gulir, dan hanya kontainer tabel yang menggulir mendatar. Respons penilaian panjang tidak lagi dipotong dengan truncation.
- Empty state kini membedakan data kosong dan hasil filter kosong. Tersedia penjelasan sesuai sumber data serta aksi hapus filter. Kesalahan daftar mempunyai aksi coba lagi dan status loading diumumkan ke pembaca layar.
- Form memakai penanda `*` pada 42 kontrol wajib yang sudah ada, dengan catatan penjelas di editor. Tidak ada aturan required/validasi yang diubah.
- Kesalahan validasi backend sekarang dipetakan ke kontrol yang sesuai pada editor, settings, kebijakan dan penilaian manual. Pesan tampil di dekat field, kontrol mendapat `aria-invalid`/`aria-describedby`, ringkasan tetap tampil, dan fokus berpindah ke kontrol pertama yang perlu dikoreksi. Nama field mengikuti kunci payload yang sudah ada; API/payload tidak diubah.
- Lima dialog (editor, preview, penggunaan, penilaian manual dan detail audit) mempunyai fokus awal, batas Tab/Shift+Tab, Escape, pengembalian fokus, penguncian gulir latar dan konten latar inert. Preview/riwayat di atas editor tetap mempunyai urutan fokus yang benar.
- Spacing admin mengikuti interval 4/8 px, tipografi tetap besar, warna menggunakan token yang sudah tersedia, dan tombol/field tetap memiliki ukuran sentuh yang nyaman.

## Validasi

- Kompilasi `@vue/compiler-sfc` untuk script, template dan scoped CSS: lulus.
- `node --test tests/Frontend/admin-policy.test.mjs`: 3/3 lulus, termasuk pelestarian score scale dan inheritance ketika paket disimpan.
- `tests/Browser/admin-layout.spec.js` menambahkan matriks 320, 375, 768, 1024, 1366, 1440 dan 1920 px pada light/dark untuk seluruh 13 halaman di atas beserta seluruh tab settings/kebijakan. Pemeriksaan meliputi overflow dokumen, header, loading/error, kolom tabel dan screenshot.
- Skenario browser tambahan mencakup seluruh pilihan jenis soal, matching, semua form create, section paket/pemilihan manual, preview bertingkat, riwayat penggunaan, detail audit, hasil pencarian kosong, fokus keyboard dan Escape.
- Satu skenario tambahan mengirim kode kompetensi duplikat ke API sebenarnya, mengharapkan HTTP 422, lalu memeriksa pesan di dekat field, fokus koreksi, relasi aksesibilitas dan pembersihan error ketika editor dibuka kembali.
- Hasil run browser akhir untuk `admin-layout.spec.js`: **5/5 lulus**. Dua kasus matriks melakukan live resize pada 13 halaman × 7 lebar × 2 tema (**182 kombinasi halaman/viewport/tema**), lalu seluruh enam tab settings dan tiga kebijakan pada setiap lebar. Dua kasus builder memeriksa mobile/light dan desktop/dark; satu kasus memverifikasi error 422 dari backend sebenarnya.
- Screenshot yang dirender diperiksa untuk matching pada 320 px dan paket pada desktop/dark. Temuan header/footer modal yang ikut bergulir telah diperbaiki pada sistem modal bersama; pengujian akhir memastikan header serta footer tetap sepenuhnya di viewport setelah konten panjang digulir. Screenshot halaman dan policy tersimpan dalam artefak Playwright.
- Teks placeholder pencarian disingkat agar muat pada 320 px, sementara nama aksesibel `Search records` dipertahankan. Indikator wajib dan pesan kesalahan memakai token semantik `--danger` yang menyesuaikan light/dark.

## Batas lingkup

Endpoint, struktur payload, penyimpanan soal/jawaban, kebijakan ujian, kalkulasi nilai, penerbitan sertifikat, impor/ekspor dan logika backend tidak diubah. Dialog penilaian manual dengan respons aktual memerlukan fixture hasil ujian; cakupan fungsional tersebut dilaporkan melalui skenario workflow yang sudah tersedia.
