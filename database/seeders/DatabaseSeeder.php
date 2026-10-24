<?php

namespace Database\Seeders;

use App\Models\{Competency, ExamPackage, ExamSection, MediaAsset, ProficiencyLevel, Question, QuestionGroup, QuestionType, QuestionVersion, SystemSetting, User};
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->accounts();
        foreach ([
            ['listening', 'Menyimak', 'Listening', 'Memahami percakapan, pengumuman, dan instruksi lisan.', 'headphones', '#8b5cf6'],
            ['grammar', 'Tata Bahasa', 'Grammar & Structure', 'Menggunakan struktur kalimat dan bentuk kata secara tepat.', 'languages', '#3b82f6'],
            ['reading', 'Membaca', 'Reading', 'Menemukan informasi, gagasan utama, dan makna dalam teks.', 'book-open', '#10b981'],
            ['vocabulary', 'Kosakata', 'Vocabulary', 'Memahami makna dan penggunaan kata dalam konteks.', 'spell-check', '#f59e0b'],
            ['writing', 'Menulis', 'Writing', 'Menyusun tulisan yang jelas, runtut, dan sesuai tujuan.', 'pen-line', '#f43f5e'],
            ['speaking', 'Berbicara', 'Speaking', 'Menyampaikan gagasan lisan dengan jelas dan relevan.', 'mic', '#06b6d4'],
            ['communication', 'Komunikasi Kontekstual', 'Communication', 'Menggunakan bahasa dalam situasi sosial dan profesional.', 'messages-square', '#6366f1'],
        ] as $i => [$code, $name, $english, $description, $icon, $color]) {
            Competency::firstOrCreate(['code' => $code], ['name' => $name, 'name_en' => $english, 'description' => $description, 'icon' => $icon, 'color' => $color, 'sort_order' => $i + 1, 'active' => true]);
        }
        foreach ([['A1', 'Beginner', 0, 'Memahami ungkapan sangat sederhana.'], ['A2', 'Elementary', 20, 'Berkomunikasi dalam situasi rutin sederhana.'], ['B1', 'Intermediate', 40, 'Berkomunikasi tentang kehidupan sehari-hari.'], ['B2', 'Upper Intermediate', 60, 'Berkomunikasi mandiri dalam konteks yang lebih kompleks.'], ['C1', 'Advanced', 75, 'Menggunakan bahasa dalam konteks profesional dan akademik.'], ['C2', 'Proficient', 90, 'Memahami dan menggunakan bahasa dengan kemahiran sangat tinggi.']] as $i => [$code, $name, $minimum, $description]) {
            ProficiencyLevel::firstOrCreate(['code' => $code], ['name' => $name, 'min_score' => $minimum, 'description' => $description.' Klasifikasi internal INBAF; belum merupakan kesetaraan CEFR resmi.', 'sort_order' => $i + 1]);
        }
        foreach (['single_choice' => 'Pilihan Ganda', 'multiple_response' => 'Pilihan Jamak', 'true_false' => 'Benar / Salah', 'matching' => 'Memasangkan', 'drag_drop' => 'Seret dan Letakkan', 'fill_blank' => 'Isian Rumpang', 'dropdown' => 'Dropdown Completion', 'short_answer' => 'Jawaban Singkat', 'essay' => 'Esai', 'sentence_ordering' => 'Urutan Kalimat', 'word_ordering' => 'Urutan Kata', 'listening' => 'Berbasis Audio', 'reading' => 'Pemahaman Bacaan', 'image' => 'Berbasis Gambar', 'speaking' => 'Rekaman Berbicara', 'video' => 'Berbasis Video', 'error_identification' => 'Identifikasi Kesalahan', 'conversation' => 'Percakapan Kontekstual'] as $code => $name) {
            QuestionType::firstOrCreate(['code' => $code], ['name' => $name, 'automatic' => ! in_array($code, ['essay', 'speaking'])]);
        }
        foreach (['system_name' => 'INBAF', 'tagline' => 'Your Indonesian journey, measured.', 'institution_name' => 'INBAF Language Assessment', 'institution_address' => 'Indonesia', 'contact_email' => 'hello@inbaf.test', 'footer' => 'Indonesian Language Assessment Platform for Foreign Students', 'primary_color' => '#193C31', 'default_locale' => 'id', 'registration_enabled' => true, 'score_scale' => 100, 'certificate_validity_months' => 24, 'certificate_template' => 'modern', 'signer_name' => 'Direktur INBAF', 'signer_title' => 'Direktur Asesmen Bahasa', 'require_fullscreen' => true, 'tab_switch_limit' => 3, 'session_policy' => 'flexible', 'retention_days' => 365, 'email_notifications_enabled' => false, 'ai_enabled' => false, 'storage_driver' => 'local', 'max_upload_mb' => 25] as $key => $value) SystemSetting::firstOrCreate(['key' => $key], ['value' => $value]);

        $passage = QuestionGroup::firstOrCreate(['title' => 'Konten awal · Perpustakaan Kampus'], ['kind' => 'passage', 'content' => '<p>Perpustakaan Universitas Nusantara membuka layanan baru bagi mahasiswa internasional. Setiap hari Senin hingga Jumat, perpustakaan buka pukul 08.00 sampai 20.00. Pada hari Sabtu, layanan berakhir pukul 14.00. Perpustakaan tutup pada hari Minggu dan hari libur nasional.</p><p>Mahasiswa dapat meminjam paling banyak tiga buku selama tujuh hari. Untuk meminjam buku, mahasiswa harus menunjukkan kartu mahasiswa yang masih berlaku. Buku referensi, seperti kamus dan ensiklopedia, hanya boleh dibaca di ruang perpustakaan. Perpanjangan peminjaman dapat dilakukan melalui situs perpustakaan jika buku tersebut belum dipesan oleh pembaca lain.</p><p>Ruang diskusi tersedia di lantai dua. Setiap kelompok dapat menggunakan ruang tersebut selama dua jam setelah melakukan pemesanan. Makanan tidak diizinkan di dalam perpustakaan, tetapi pengunjung boleh membawa air minum dalam botol tertutup. Kebijakan ini bertujuan menjaga buku dan peralatan tetap bersih.</p><p>Mulai bulan ini, perpustakaan juga menyelenggarakan kelas percakapan Bahasa Indonesia setiap Rabu sore. Kelas ini terbuka bagi seluruh mahasiswa internasional dan tidak dipungut biaya. Peserta perlu mendaftar sehari sebelumnya karena jumlah tempat terbatas. Melalui kegiatan ini, perpustakaan berharap mahasiswa lebih mudah beradaptasi dengan kehidupan kampus dan saling bertukar pengalaman.</p>']);
        $audio = null;
        if (Storage::disk('local')->exists('starter/argo-muria.ogg')) $audio = MediaAsset::firstOrCreate(['path' => 'starter/argo-muria.ogg'], ['original_name' => 'Train Announcement in Indonesia - Kereta Api Argo Muria.ogg', 'mime' => 'audio/ogg', 'size' => Storage::disk('local')->size('starter/argo-muria.ogg'), 'kind' => 'audio', 'metadata' => ['attribution' => 'Germartin1 / Wikimedia Commons · CC BY-SA 4.0', 'source_url' => 'https://commons.wikimedia.org/wiki/File:Train_Announcement_in_Indonesia_-_Kereta_Api_Argo_Muria.ogg', 'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/']]);
        $audioGroup = QuestionGroup::firstOrCreate(['title' => 'Konten awal · Pengumuman kereta Argo Muria'], ['kind' => 'audio', 'media_asset_id' => $audio?->id, 'metadata' => ['attribution' => 'Germartin1 / Wikimedia Commons · CC BY-SA 4.0', 'max_plays' => 2, 'allow_replay' => true]]);
        if ($audio && ! $audioGroup->media_asset_id) $audioGroup->update(['media_asset_id' => $audio->id]);
        $imageGroup = $this->localMediaGroup('starter/library-sign.png', 'image/png', 'image', 'Konten awal · Papan informasi perpustakaan');
        $videoGroup = $this->localMediaGroup('starter/library-information.mp4', 'video/mp4', 'video', 'Konten awal · Video informasi perpustakaan');

        $options = fn (array $texts) => collect($texts)->map(fn ($text, $index) => ['id' => (string) $index, 'text' => $text])->values()->all();
        $items = $options(['pagi', 'siang', 'malam']);
        $jobs = $options(['dokter', 'guru', 'petani']);
        $work = $options(['sekolah', 'rumah sakit', 'sawah']);
        $questions = [
            ['GRA-001', 'grammar', 'single_choice', 'Kata depan yang tepat', 'Saya tinggal ___ Jakarta.', ['correct' => '0'], $options(['di', 'ke', 'dari', 'pada'])],
            ['GRA-002', 'grammar', 'multiple_response', 'Memilih kata baku', 'Pilih semua kata yang ditulis dalam bentuk baku.', ['correct' => ['0', '2']], $options(['aktivitas', 'aktifitas', 'apotek', 'apotik'])],
            ['GRA-003', 'grammar', 'true_false', 'Kalimat pasif', 'Kalimat “Buku itu dibaca oleh Nina” merupakan kalimat pasif.', ['correct' => 'true'], [['id' => 'true', 'text' => 'Benar'], ['id' => 'false', 'text' => 'Salah']]],
            ['GRA-004', 'grammar', 'fill_blank', 'Imbuhan dalam kalimat', 'Setiap pagi, Rina ___ buku di perpustakaan. (baca)', ['correct' => 'membaca', 'accepted' => ['Membaca']], []],
            ['GRA-005', 'grammar', 'dropdown', 'Konjungsi', 'Saya membawa payung [1] hari ini hujan.', ['correct' => ['1' => 'karena']], $options(['karena', 'tetapi', 'atau']), ['blanks' => [['id' => '1', 'label' => 'Konjungsi', 'options' => [['id' => 'karena', 'text' => 'karena'], ['id' => 'tetapi', 'text' => 'tetapi'], ['id' => 'atau', 'text' => 'atau']]]]]],
            ['GRA-006', 'grammar', 'word_ordering', 'Menyusun kata', 'Susun kata menjadi kalimat yang benar.', ['correct' => ['0', '1', '2', '3', '4']], $options(['Saya', 'belajar', 'bahasa', 'Indonesia', 'setiap hari'])],
            ['GRA-007', 'grammar', 'error_identification', 'Kalimat efektif', 'Bagian mana yang perlu dihapus dari kalimat “Para mahasiswa-mahasiswa mengikuti ujian”?', ['correct' => '1'], $options(['Para', '-mahasiswa (pengulangan kedua)', 'mengikuti', 'ujian'])],
            ['VOC-001', 'vocabulary', 'matching', 'Profesi dan tempat kerja', 'Pasangkan profesi dengan tempat kerjanya.', ['correct' => ['0' => '1', '1' => '0', '2' => '2']], $work, ['left_items' => $jobs, 'right_items' => $work]],
            ['VOC-002', 'vocabulary', 'drag_drop', 'Sapaan menurut waktu', 'Pasangkan waktu dengan sapaan yang sesuai.', ['correct' => ['0' => '0', '1' => '1', '2' => '2']], $options(['Selamat pagi', 'Selamat siang', 'Selamat malam']), ['left_items' => $items, 'right_items' => $options(['Selamat pagi', 'Selamat siang', 'Selamat malam'])]],
            ['VOC-003', 'vocabulary', 'short_answer', 'Lawan kata', 'Apa lawan kata “besar”?', ['correct' => 'kecil', 'accepted' => ['kecil']], []],
            ['VOC-004', 'vocabulary', 'single_choice', 'Makna kata dalam konteks', '“Harga tiket itu terjangkau.” Kata “terjangkau” berarti …', ['correct' => '0'], $options(['dapat dibeli dengan biaya yang wajar', 'sangat jauh', 'tidak tersedia', 'sulit ditemukan'])],
            ['REA-001', 'reading', 'reading', 'Informasi tersurat', 'Pada hari apa perpustakaan tutup?', ['correct' => '1'], $options(['Rabu', 'Minggu', 'Sabtu', 'Jumat']), [], $passage->id],
            ['REA-002', 'reading', 'single_choice', 'Ketentuan peminjaman', 'Berapa lama mahasiswa boleh meminjam buku?', ['correct' => '0'], $options(['Tujuh hari', 'Dua jam', 'Satu bulan', 'Tiga hari']), [], $passage->id],
            ['REA-003', 'reading', 'true_false', 'Memahami aturan', 'Kamus boleh dibawa pulang selama tujuh hari.', ['correct' => 'false'], [['id' => 'true', 'text' => 'Benar'], ['id' => 'false', 'text' => 'Salah']], [], $passage->id],
            ['REA-004', 'reading', 'multiple_response', 'Layanan mahasiswa internasional', 'Pilih dua pernyataan yang sesuai dengan bacaan.', ['correct' => ['0', '2']], $options(['Kelas percakapan diadakan setiap Rabu.', 'Kelas percakapan harus dibayar.', 'Pendaftaran dilakukan sehari sebelumnya.', 'Ruang diskusi dapat dipakai tanpa pemesanan.']), [], $passage->id],
            ['WRI-001', 'writing', 'sentence_ordering', 'Paragraf yang runtut', 'Susun kalimat menjadi urutan kegiatan yang masuk akal.', ['correct' => ['0', '1', '2', '3']], $options(['Pertama, saya menyiapkan bahan masakan.', 'Kemudian, saya memasak sayuran.', 'Setelah matang, saya menyajikannya di piring.', 'Akhirnya, saya menikmati makan siang.'])],
            ['WRI-002', 'writing', 'essay', 'Email kepada dosen', 'Tulis email 100–150 kata kepada dosen Anda untuk meminta perubahan jadwal konsultasi. Jelaskan alasan, usulkan waktu baru, dan gunakan bahasa yang sopan.', [], [], ['min_words' => 100, 'max_words' => 150, 'rubric' => ['Tata bahasa' => 20, 'Kosakata' => 20, 'Organisasi tulisan' => 20, 'Koherensi' => 20, 'Kesesuaian tugas' => 20]]],
            ['SPE-001', 'speaking', 'speaking', 'Perkenalan dan rencana belajar', 'Perkenalkan diri Anda dan ceritakan alasan Anda belajar Bahasa Indonesia. Jelaskan satu kegiatan yang ingin Anda lakukan selama tinggal di Indonesia. Rekam jawaban selama 60–90 detik.', [], [], ['preparation_seconds' => 30, 'recording_seconds' => 90, 'rubric' => ['Pelafalan' => 20, 'Kelancaran' => 20, 'Ketepatan bahasa' => 20, 'Kosakata' => 20, 'Relevansi jawaban' => 20]]],
            ['COM-001', 'communication', 'conversation', 'Meminta petunjuk arah', 'Anda tersesat dan ingin bertanya kepada orang yang belum Anda kenal. Ungkapan mana yang paling tepat?', ['correct' => '0'], $options(['Permisi, boleh saya bertanya jalan menuju stasiun?', 'Hei, kamu harus antar saya!', 'Jalan ini milik siapa?', 'Saya tidak mau pergi ke stasiun.'])],
            ['COM-002', 'communication', 'image', 'Memahami petunjuk publik', 'Perhatikan papan informasi. Pukul berapa perpustakaan mulai dibuka?', ['correct' => '0'], $options(['08.00', '09.00', '10.00', '16.00']), ['alt' => 'Papan informasi perpustakaan kota.', 'source_label' => 'Ilustrasi asli INBAF, konten awal.'], $imageGroup?->id],
            ['COM-003', 'communication', 'video', 'Informasi jadwal', 'Tonton informasi pada video. Pukul berapa perpustakaan tutup?', ['correct' => '2'], $options(['08.00', '09.00', '16.00', '20.00']), ['note' => 'Konten awal: video tanpa suara berisi informasi perpustakaan dalam Bahasa Indonesia.'], $videoGroup?->id],
            ['COM-004', 'communication', 'conversation', 'Tanggapan sopan', 'Petugas berkata, “Silakan menunggu sebentar.” Apa tanggapan yang paling tepat?', ['correct' => '0'], $options(['Baik, terima kasih.', 'Tidak, saya sudah makan.', 'Nama saya Budi.', 'Besok hari Minggu.'])],
            ['LIS-001', 'listening', 'listening', 'Pengumuman transportasi', 'Dengarkan pengumuman. Pengumuman tersebut menyambut penumpang pada moda transportasi apa?', ['correct' => '1'], $options(['Kapal laut', 'Kereta api', 'Pesawat', 'Bus']), ['max_plays' => 2, 'allow_replay' => true, 'note' => 'Rekaman manusia: Germartin1 / Wikimedia Commons, CC BY-SA 4.0.'], $audioGroup->id],
        ];
        $ids = [];
        foreach ($questions as $row) {
            [$code, $competency, $type, $title, $prompt, $schema, $choices] = $row;
            $ready = ! (($type === 'listening' && ! $audio) || ($type === 'image' && ! $imageGroup) || ($type === 'video' && ! $videoGroup));
            $question = Question::firstOrCreate(['code' => 'START-'.$code], ['competency_id' => Competency::where('code', $competency)->value('id'), 'type' => $type, 'title' => 'Konten awal · '.$title, 'instructions' => in_array($type, ['multiple_response']) ? 'Pilih semua jawaban yang benar.' : 'Baca instruksi dan berikan jawaban terbaik Anda.', 'prompt' => $prompt, 'difficulty' => 'easy', 'weight' => in_array($type, ['essay', 'speaking']) ? 3 : 1, 'status' => $ready ? 'active' : 'draft', 'version' => 1, 'options' => $choices, 'answer_schema' => $schema, 'allowed_test_types' => ['practice', 'placement', 'certification'], 'metadata' => ($row[7] ?? []) + ['starter_content' => true], 'question_group_id' => $row[8] ?? null, 'explanation' => in_array($type, ['essay', 'speaking']) ? 'Dinilai oleh penilai manusia menggunakan rubrik yang ditampilkan.' : 'Tinjau kembali konteks kalimat dan instruksi soal.']);
            QuestionVersion::firstOrCreate(['question_id' => $question->id, 'version' => 1], ['snapshot' => $question->toArray()]);
            if ($question->status === 'active') $ids[$competency][] = $question->id;
        }
        $this->starterPackage('INBAF-PLACEMENT', 'INBAF Placement Test', 'Kenali titik awal kemampuan Bahasa Indonesia Anda melalui tata bahasa, kosakata, membaca, dan komunikasi. Paket konten awal untuk orientasi; bukan tes kesetaraan resmi.', ['grammar', 'vocabulary', 'reading', 'communication', ...($audio ? ['listening'] : [])], $ids, 40, false);
        $this->starterPackage('INBAF-ACADEMIC', 'INBAF Academic', 'Latih pemahaman bacaan, struktur bahasa, tulisan akademik, dan komunikasi lisan. Esai dan speaking dinilai manual sebelum hasil akhir diterbitkan. Konten awal.', ['reading', 'grammar', 'writing', 'speaking'], $ids, 60, true);
        $this->starterPackage('INBAF-DAILY', 'INBAF Daily Communication', 'Ukur komunikasi sehari-hari melalui konteks kampus, petunjuk gambar, kosakata, dan pengumuman autentik. Paket konten awal.', ['communication', 'vocabulary', ...($audio ? ['listening'] : [])], $ids, 25, true);
    }

    private function accounts(): void
    {
        foreach ([['superadmin', 'INBAF Administrator', 'admin@inbaf.test', 'INBAF_ADMIN_PASSWORD'], ['participant', 'Alex Morgan', 'participant@inbaf.test', 'INBAF_PARTICIPANT_PASSWORD']] as [$role, $name, $email, $variable]) {
            if (app()->isProduction() && $role === 'participant' && ! env('INBAF_SEED_DEMO', false)) continue;
            $email = env($role === 'superadmin' ? 'INBAF_ADMIN_EMAIL' : 'INBAF_PARTICIPANT_EMAIL', $email);
            if (User::where('email', $email)->exists()) continue;
            $password = env($variable);
            if (app()->isProduction() && ! $password) {
                if ($role === 'participant') continue;
                throw new \RuntimeException('Set INBAF_ADMIN_PASSWORD before seeding production.');
            }
            $generated = ! $password;
            $password ??= Str::password(20);
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role, 'active' => true]);
            if ($role === 'participant') $user->profile()->create(['nationality' => 'Australia', 'native_language' => 'English', 'institution' => 'International Student', 'country' => 'Indonesia']);
            if ($generated) $this->command?->warn('Local '.$role.' login: '.$email.' / '.$password.' (store this password securely)');
            else $this->command?->info('Created '.$role.' account: '.$email.' (password from '.$variable.')');
        }
    }

    private function localMediaGroup(string $path, string $mime, string $kind, string $title): ?QuestionGroup
    {
        if (! Storage::disk('local')->exists($path)) return null;
        $media = MediaAsset::firstOrCreate(['path' => $path], ['original_name' => basename($path), 'mime' => $mime, 'size' => Storage::disk('local')->size($path), 'kind' => $kind, 'metadata' => ['source_label' => 'Original INBAF starter material']]);
        return QuestionGroup::firstOrCreate(['title' => $title], ['kind' => $kind, 'media_asset_id' => $media->id, 'metadata' => ['source_label' => 'Original INBAF starter material']]);
    }

    private function starterPackage(string $code, string $name, string $description, array $competencies, array $ids, int $duration, bool $certificate): void
    {
        $testType = match ($code) { 'INBAF-PLACEMENT' => 'placement', 'INBAF-DAILY' => 'practice', default => 'certification' };
        $package = ExamPackage::firstOrCreate(['code' => $code], ['test_type' => $testType, 'policy_overrides' => ['lock_sections' => false], 'name' => $name, 'description' => $description, 'published' => true, 'duration_minutes' => $duration, 'attempts_limit' => 5, 'pass_score' => 60, 'certificate_enabled' => $testType === 'certification' && $certificate, 'question_selection' => 'manual', 'settings' => ['lock_sections' => false, 'randomize_options' => true, 'starter_content' => true, 'instructions' => ['Kerjakan mandiri dan baca setiap instruksi dengan cermat.', 'Jawaban tersimpan secara otomatis. Pastikan indikator penyimpanan berhasil.', 'Hasil menggunakan klasifikasi internal INBAF, bukan kesetaraan sertifikasi resmi.']]]);
        if ($package->sections()->exists()) return;
        foreach ($competencies as $index => $code) {
            $competency = Competency::where('code', $code)->firstOrFail();
            ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => $competency->name, 'position' => $index + 1, 'duration_minutes' => (int) floor($duration / count($competencies)), 'weight' => 1, 'question_count' => count($ids[$code] ?? []), 'question_ids' => $ids[$code] ?? [], 'randomize' => true]);
        }
    }
}

