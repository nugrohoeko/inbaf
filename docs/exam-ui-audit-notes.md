# Audit UI domain ujian, hasil, dan analitik

Tanggal: 24 Oktober 2026. Ruang lingkup ini merupakan bagian audit seluruh aplikasi; inventaris dan verifikasi lintas role dicatat pada laporan utama.

## Inventaris penggunaan

| Komponen | Halaman / konteks yang memakai komponen |
| --- | --- |
| `ExamWorkspace.vue` | `/exam/{attempt}` untuk practice, placement, certification; instruksi ringkas, timer sesi/seksi/soal, navigasi nomor soal, review, status sinkronisasi, konfirmasi pengiriman |
| `QuestionRenderer.vue` | Pelaksanaan soal dan pratinjau pengelola; pilihan tunggal/jamak, benar/salah, isian/dropdown, jawaban pendek, esai, matching, drag/drop, urutan kalimat/kata, bacaan, gambar, audio/video, speaking |
| `ScoreChart.vue` | Hasil peserta `/results/{attempt}`; grafik skor dan radar kompetensi |
| `DashboardAnalytics.vue` | Dashboard peserta dan Superadmin; tren asesmen bulanan, distribusi level, rerata kompetensi |
| `ResultInsights.vue` | Rekomendasi level, kekuatan kompetensi, fokus latihan, perbandingan dengan hasil sebelumnya |
| `RichEditor.vue` | Editor konten Question Builder dan formulir pengelola yang menggunakan konten kaya |

## Temuan dan perbaikan

1. Bacaan selalu tampil di atas soal; desktop belum memiliki area bacaan dan jawaban berdampingan. Ditambahkan grid berbasis lebar komponen (container query 48rem). Pada area luas, bacaan dan jawaban terpisah secara proporsional, bacaan dapat digulir dan difokuskan keyboard. Pada area sempit, kedua area ditumpuk tanpa tinggi bacaan tetap.
2. Sidebar ujian mengasumsikan tinggi header tetap, padahal header dapat membungkus ketika nama paket panjang atau preferensi teks besar. `ResizeObserver` mengukur tinggi header aktual untuk offset sticky sidebar/bacaan dan tinggi viewport yang tersedia. Timer tetap terlihat pada header sticky; kontrol membungkus pada ponsel.
3. Pilihan jawaban dan nomor soal aktif memiliki sisa warna biru dan kontras putih pada lime. Komponen memakai token tema yang sama, dengan teks emerald pada lime, penanda fokus jelas, serta status aktif lewat `aria-current`.
4. Matching dan urutan jawaban dapat memaksa lebar minimum melebihi ruang ponsel. Diganti grid dengan kolom `minmax(0, ...)`, kontrol minimal 44px, teks panjang dapat membungkus, dan select menjadi satu kolom ketika sempit. Media serta rich table dibatasi dalam areanya sendiri.
5. Dialog pengiriman dan drawer nomor soal belum mengelola fokus keyboard. Ditambahkan fokus awal, siklus Tab/Shift+Tab, Escape, pengembalian fokus, inert pada drawer tertutup/konten di belakang overlay, penutupan saat beralih ke desktop, dan penguncian scroll badan halaman tanpa menyentuh aturan ujian atau proses submit/autosave.
6. Pemeriksaan screenshot aktual 1440px menemukan tombol drawer mobile yang muncul pada desktop dan nomor seksi yang terbungkus. Visibility tombol kini dibatasi breakpoint, nomor seksi tidak menyusut/membungkus. Screenshot 320px juga menemukan digit timer terbungkus; angka waktu diberi nowrap dan timer memakai baris penuh di layar kecil. Assertion regresi ditambahkan.
7. Grafik memakai tinggi tetap ketika label kompetensi bertambah. Grafik kompetensi kini menambah tinggi berdasarkan jumlah baris, mempertahankan ukuran teks, membungkus label, dan memakai bar horizontal. Screenshot grafik 320px menemukan huruf awal Communication terpotong; helper bersama `resources/js/chart-labels.js` kini membungkus menurut pengukuran lebar canvas (termasuk kata tanpa spasi), menunggu webfont siap sebelum pengukuran, menyediakan gutter kiri, dan menghitung tinggi dari jumlah baris label. Radar beralih ke bar ketika ruang tidak cukup untuk label sekelilingnya.
8. Canvas dan legenda donut tidak menyediakan akses nilai yang andal lewat keyboard/touch. Ditambahkan rincian nilai HTML dari data backend dan legenda HTML berisi level serta jumlahnya. Tidak ada data contoh atau nilai analitik buatan.
9. Kartu insight dapat menyempitkan nama kompetensi panjang dan unit perbandingan. Ditambahkan grid `minmax(0, ...)`, pembungkusan, ukuran paragraf 18px, heading section 22px, dan gap/padding berbasis 8px.
10. Toolbar rich editor hanya menjalankan pemformatan saat mousedown. Tombol sekarang dapat dijalankan dengan click/keyboard, memiliki label format yang jelas, dapat membungkus, dan area editor memiliki fokus, overflow, dan ukuran teks yang konsisten.

## Batas perubahan

- Tidak mengubah endpoint, payload jawaban, perhitungan nilai, aktivasi timer server, aturan batas waktu, jatah playback/rekaman, urutan penyimpanan, atau pemulihan jawaban.
- Preferensi ukuran teks yang sudah tersedia dipertahankan; tidak menambahkan pilihan ukuran teks baru.
- Style khusus berada di komponen; token tema dan layout global ditangani laporan utama.

## Verifikasi

- Enam komponen berhasil dikompilasi dengan `@vue/compiler-sfc`, mencakup script, template, dan scoped styles.
- Ditambahkan `tests/Browser/exam-layout.spec.js`: dua skenario menggunakan backend/database pengujian nyata, tujuh lebar 320/375/768/1024/1366/1440/1920, autosave, split/stack bacaan, drawer, fokus dialog/Escape, nilai grafik yang dibandingkan dengan respons API, analitik pengelola, serta toolbar editor lewat keyboard.
- Pengujian juga memeriksa extent glyph label kategori dari instance Chart.js aktual pada tujuh lebar dan ukuran teks extra large di 320px. Screenshot menunggu font/notifikasi selesai, melepas fokus, lalu menggulir ke paling atas untuk menghindari artefak header sticky.
- Screenshot disimpan oleh pengujian di `tmp/qa/ui-audit/` dengan nama `exam-reading-{width}.png`, `result-charts-{width}.png`, dan `admin-analytics-{width}.png`.
- Hasil akhir: kedua skenario domain lulus. Bersama regresi navigasi saat resize, run akhir grafik lulus 3/3. Pemeriksaan batas glyph, tujuh viewport, teks extra large 320 px, autosave, split/stack, drawer, dialog/fokus, nilai API dan pemformatan keyboard seluruhnya lulus. Build produksi final juga lulus. Hasil gabungan 51 skenario unik dicatat dalam `dashboard-ui-audit.md`.
