<?php

namespace App\Services;

use App\Models\{AuditLog, ExamAnswer, ExamAttempt, ExamPackage, MediaAsset, Question, QuestionUsage, QuestionVersion, SystemSetting, User};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttemptService
{
    public function __construct(private readonly ScoringService $scoring) {}

    public function start(User $user, ExamPackage $package, ?string $accessCode = null): ExamAttempt
    {
        return DB::transaction(function () use ($user, $package, $accessCode) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $package = ExamPackage::with(['sections.competency', 'testType'])->findOrFail($package->id);
            if (! $user->active || ! $package->published || ! $package->testType?->active) $this->fail('Paket ujian belum tersedia.');
            $settings = $package->settings ?? [];
            $global = SystemSetting::pluck('value', 'key')->all();
            $rules = $package->effectivePolicy();
            $timed = (bool) $rules['timer_enabled'];
            $lockSections = (bool) $rules['lock_sections'];
            if (! empty($settings['access_code']) && ! hash_equals((string) $settings['access_code'], (string) $accessCode)) $this->fail('Kode akses ujian tidak sesuai.');
            if (! empty($settings['starts_at']) && now()->lt(Carbon::parse($settings['starts_at']))) $this->fail('Periode ujian belum dimulai.');
            if (! empty($settings['ends_at']) && now()->gt(Carbon::parse($settings['ends_at']))) $this->fail('Periode ujian telah berakhir.');
            $existing = ExamAttempt::where('user_id', $user->id)->where('exam_package_id', $package->id)->where('test_type', $package->test_type)->where('status', 'in_progress')->lockForUpdate()->first();
            if ($existing && (! $existing->expires_at || $existing->expires_at->isFuture())) return $existing->load('package');
            if ($existing) $this->submit($existing);
            $count = ExamAttempt::where('user_id', $user->id)->where('exam_package_id', $package->id)->where('test_type', $package->test_type)->count();
            if ($rules['attempts_limit'] > 0 && $count >= $rules['attempts_limit']) $this->fail('Batas kesempatan ujian telah tercapai.');
            if (! $package->sections->count()) $this->fail('Paket belum memiliki sesi ujian.');
            $started = now(); $expires = $timed ? $started->copy()->addMinutes($package->duration_minutes) : null; $cursor = $started->copy(); $sections = [];
            foreach ($package->sections as $section) {
                if (! $section->competency?->active) $this->fail('Salah satu kompetensi tidak aktif.');
                $query = Question::with('group.mediaAsset')->where('competency_id', $section->competency_id)->where('status', 'active')
                    ->whereJsonContains('allowed_test_types', $package->test_type)
                    ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'));
                if ($package->test_type !== 'certification') $query->where('confidential', false);
                if ($section->difficulties) $query->whereIn('difficulty', $section->difficulties);
                if ($package->question_selection === 'manual' && count($section->question_ids ?? [])) {
                    $records = $query->whereIn('id', $section->question_ids)->orderBy('id')->lockForUpdate()->get()->sortBy(fn ($q) => array_search($q->id, $section->question_ids));
                    if ($records->count() !== count(array_unique($section->question_ids))) $this->fail('Soal pilihan tidak lengkap, tidak diizinkan untuk jenis tes ini, rahasia, atau telah mencapai batas penggunaan.');
                    if ($section->randomize) $records = $records->shuffle();
                    if ($records->count() < $section->question_count) $this->fail('Pilihan soal belum mencukupi jumlah soal sesi.');
                    $records = $records->take($section->question_count);
                } else {
                    // Lock candidates in a stable order so concurrent users cannot exceed usage caps.
                    $records = $query->orderBy('id')->lockForUpdate()->get();
                    if ($section->randomize) $records = $records->shuffle();
                    $records = $records->take($section->question_count);
                    if ($records->count() < $section->question_count) $this->fail('Jumlah soal aktif belum mencukupi untuk sesi '.$section->title.'.');
                }
                if (! $records->count()) $this->fail('Sesi '.$section->title.' belum memiliki soal.');
                $questions = [];
                foreach ($records as $question) {
                    if ($question->usage_limit !== null && $question->usage_count >= $question->usage_limit) $this->fail('Batas penggunaan salah satu soal telah tercapai.');
                    QuestionVersion::firstOrCreate(['question_id' => $question->id, 'version' => $question->version], ['snapshot' => $question->withoutRelations()->toArray()]);
                    $data = $question->toArray();
                    $data['key'] = 's'.$section->id.'-q'.$question->id.'-'.Str::lower(Str::random(6));
                    if (! $timed) $data['duration_seconds'] = null;
                    if ((in_array($question->type, ['sentence_ordering', 'word_ordering'], true) || (($rules['randomize_options'] ?? true) && ! in_array($question->type, ['matching', 'drag_drop', 'dropdown'], true))) && is_array($data['options'])) $data['options'] = collect($data['options'])->shuffle()->values()->all();
                    $questions[] = $data;
                    $question->increment('usage_count');
                }
                $sectionEnd = $timed ? $cursor->copy()->addMinutes($section->duration_minutes)->min($expires) : null;
                $sections[] = ['id' => $section->id, 'title' => $section->title, 'competency_id' => $section->competency_id, 'competency' => $section->competency->toArray(), 'weight' => $section->weight, 'position' => $section->position, 'duration_minutes' => $timed ? $section->duration_minutes : null, 'starts_at' => $cursor->toISOString(), 'ends_at' => $sectionEnd?->toISOString(), 'questions' => $questions];
                if ($sectionEnd) $cursor = $sectionEnd->copy();
            }
            if ($lockSections && $cursor->lt($expires)) {
                $sections[array_key_last($sections)]['ends_at'] = $expires->toISOString();
            }
            foreach (['logo_path' => 'logo_media_id', 'background_path' => 'certificate_background_media_id', 'signature_path' => 'certificate_signature_media_id'] as $pathKey => $mediaKey) {
                $global[$pathKey] = ! empty($global[$mediaKey]) ? MediaAsset::whereKey($global[$mediaKey])->whereIn('mime', ['image/png', 'image/jpeg', 'image/webp'])->value('path') : null;
            }
            $policy = ['enabled' => $package->test_type === 'certification' && $package->certificate_enabled, 'pass_score' => $package->pass_score, 'expiry_months' => $global['certificate_validity_months'] ?? 24, 'template' => $global['certificate_template'] ?? 'modern', 'institution' => $global['institution_name'] ?? 'INBAF Language Assessment', 'signer_name' => $global['certificate_signer_name'] ?? $global['signer_name'] ?? 'Direktur INBAF', 'signer_title' => $global['certificate_signer_title'] ?? $global['signer_title'] ?? 'Direktur', 'primary_color' => $global['primary_color'] ?? '#101F43', 'logo_path' => $global['logo_path'], 'background_path' => $global['background_path'], 'signature_path' => $global['signature_path'], 'paper' => $global['certificate_paper'] ?? 'a4', 'orientation' => $global['certificate_orientation'] ?? 'landscape'];
            $rules['session_policy'] = $lockSections ? 'sequential' : 'flexible';
            $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => $package->test_type, 'started_at' => $started, 'expires_at' => $expires, 'status' => 'in_progress', 'snapshot' => ['test_type' => $package->test_type, 'test_type_config' => $package->testType->toArray(), 'package' => $package->withoutRelations()->toArray(), 'participant_name' => $user->name, 'certificate_policy' => $policy, 'scoring' => $package->testType->effectiveScoring($package), 'levels' => $package->testType->effectiveLevels($package), 'sections' => $sections, 'rules' => $rules]]);
            foreach ($sections as $section) foreach ($section['questions'] as $question) {
                $attempt->answers()->create(['question_id' => $question['id'], 'question_key' => $question['key'], 'requires_manual' => $this->scoring->isManual($question)]);
                QuestionUsage::create(['exam_attempt_id' => $attempt->id, 'question_id' => $question['id'], 'question_key' => $question['key'], 'question_version' => $question['version'], 'test_type' => $package->test_type, 'used_at' => $started]);
            }
            AuditLog::create(['user_id' => $user->id, 'event' => 'attempt.started', 'subject_type' => 'exam_attempt', 'subject_id' => $attempt->id, 'data' => ['exam_package_id' => $package->id, 'test_type' => $package->test_type], 'ip' => request()->ip()]);
            return $attempt->load('package');
        }, 5);
    }

    public function question(ExamAttempt $attempt, string $key): array
    {
        foreach ($attempt->snapshot['sections'] ?? [] as $section) {
            foreach ($section['questions'] as $question) if ($question['key'] === $key) return [$section, $question];
        }
        $this->fail('Soal tidak termasuk dalam ujian ini.');
    }

    public function save(ExamAttempt $attempt, string $key, mixed $response, ?bool $marked = null): mixed
    {
        return DB::transaction(function () use ($attempt, $key, $response, $marked) {
            $attempt = ExamAttempt::lockForUpdate()->findOrFail($attempt->id);
            [$section, $question] = $this->assertWritable($attempt, $key);
            if ($question['type'] === 'speaking' && $response !== null && $response !== '' && $response !== []) {
                if (! is_array($response) || empty($response['media_asset_id'])) $this->fail('Unggah rekaman speaking melalui fitur perekaman.');
                $media = MediaAsset::find($response['media_asset_id']);
                if (! $media || $media->kind !== 'audio' || (int) ($media->metadata['user_id'] ?? 0) !== $attempt->user_id || ($media->metadata['exam_attempt_id'] ?? '') !== $attempt->id || ($media->metadata['question_key'] ?? '') !== $key) $this->fail('Rekaman tidak dimiliki oleh peserta atau soal ini.');
                $response = ['media_asset_id' => $media->id, 'url' => url('/api/media/'.$media->id), 'duration' => $media->metadata['duration'] ?? null];
            }
            $answer = $this->activateAnswer($attempt, $key, $section, $question);
            $answer->response = $response;
            if ($marked !== null) $answer->marked = $marked;
            $answer->save();
            return $answer;
        }, 5);
    }

    public function activate(ExamAttempt $attempt, string $key): ExamAnswer
    {
        return DB::transaction(function () use ($attempt, $key) {
            $attempt = ExamAttempt::lockForUpdate()->findOrFail($attempt->id);
            [$section, $question] = $this->assertWritable($attempt, $key);

            return $this->activateAnswer($attempt, $key, $section, $question);
        }, 5);
    }

    /** Call only while holding the attempt lock; a revisit never changes the clock. */
    private function activateAnswer(ExamAttempt $attempt, string $key, array $section, array $question): ExamAnswer
    {
        $answer = $attempt->answers()->where('question_key', $key)->lockForUpdate()->firstOrFail();
        if ($answer->question_started_at !== null) {
            return $answer;
        }
        $started = now()->startOfSecond();
        $duration = ($attempt->snapshot['rules']['timer_enabled'] ?? true) ? ($question['duration_seconds'] ?? null) : null;
        $deadline = null;
        if ($duration !== null) {
            $deadline = $started->copy()->addSeconds(max(1, (int) $duration));
            if ($attempt->expires_at) $deadline = $deadline->min($attempt->expires_at);
            if ($attempt->snapshot['rules']['lock_sections'] ?? true) {
                $deadline = $deadline->min(Carbon::parse($section['ends_at']));
            }
        }
        $answer->forceFill(['question_started_at' => $started, 'question_expires_at' => $deadline])->save();
        AuditLog::create(['user_id' => $attempt->user_id, 'event' => 'question.activated', 'subject_type' => 'exam_answer', 'subject_id' => (string) $answer->id, 'data' => ['question_key' => $key, 'question_expires_at' => $deadline?->toISOString()], 'ip' => request()->ip()]);

        return $answer;
    }

    public function assertWritable(ExamAttempt $attempt, string $key): array
    {
        if ($attempt->status !== 'in_progress') $this->fail('Ujian sudah dikirim dan jawaban tidak dapat diubah.');
        if ($attempt->expires_at?->lte(now())) $this->fail('Waktu ujian telah habis.');
        [$section, $question] = $this->question($attempt, $key);
        $answer = $attempt->answers()->where('question_key', $key)->firstOrFail();
        if ($attempt->snapshot['rules']['lock_sections'] ?? true) {
            if (now()->lt(Carbon::parse($section['starts_at']))) {
                $exception = ValidationException::withMessages(['section' => 'Sesi ini belum dimulai.']);
                $exception->response = response()->json([
                    'message' => 'Sesi ini belum dimulai.', 'code' => 'section_not_started',
                    'question_key' => $key, 'section_starts_at' => $section['starts_at'],
                    'server_time' => now()->toISOString(), 'errors' => $exception->errors(),
                ], 422);
                throw $exception;
            }
            if (now()->gte(Carbon::parse($section['ends_at']))) {
                $exception = ValidationException::withMessages(['section' => 'Sesi ini sudah ditutup. Jawaban terakhir yang tersimpan tetap dipertahankan.']);
                $exception->response = response()->json([
                    'message' => 'Sesi ini sudah ditutup.', 'code' => 'section_closed',
                    'expired_question_key' => $key, 'section_ends_at' => $section['ends_at'],
                    'answer' => $answer, 'server_time' => now()->toISOString(),
                    'errors' => $exception->errors(),
                ], 422);
                throw $exception;
            }
        }
        $deadline = $answer->question_expires_at;
        if ($deadline && now()->gte($deadline)) {
            $exception = ValidationException::withMessages(['question_timer' => 'Waktu untuk soal ini telah habis. Jawaban terakhir yang tersimpan tetap dipertahankan.']);
            $exception->response = response()->json([
                'message' => 'Waktu untuk soal ini telah habis.', 'code' => 'question_expired',
                'expired_question_key' => $key, 'question_expires_at' => $deadline->toISOString(),
                'answer' => $answer, 'server_time' => now()->toISOString(),
                'errors' => $exception->errors(),
            ], 422);
            throw $exception;
        }
        return [$section, $question];
    }

    public function submit(ExamAttempt $attempt): ExamAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $attempt = ExamAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->status !== 'in_progress') return $attempt->load(['package', 'certificate']);
            $attempt->update(['submitted_at' => now(), 'status' => 'pending_review']);
            return $this->scoring->finalize($attempt);
        }, 5);
    }

    public function expire(ExamAttempt $attempt): ExamAttempt
    {
        return $attempt->status === 'in_progress' && $attempt->expires_at?->lte(now()) ? $this->submit($attempt) : $attempt;
    }

    public function payload(ExamAttempt $attempt): array
    {
        $attempt = $this->expire($attempt)->loadMissing('package');
        $snapshot = $attempt->snapshot;
        $sections = [];
        foreach ($snapshot['sections'] ?? [] as $section) {
            $section['questions'] = array_map(function ($question) {
                $safe = array_intersect_key($question, array_flip(['key', 'id', 'code', 'type', 'title', 'instructions', 'prompt', 'options', 'weight', 'duration_seconds', 'competency_id', 'group']));
                $safe['metadata'] = $this->publicMetadata($question['metadata'] ?? []);
                $safe['options'] = array_map(fn ($option) => is_array($option) ? array_intersect_key($option, array_flip(['id', 'text', 'label', 'image_url', 'audio_url'])) : $option, $safe['options'] ?? []);
                if (! empty($safe['group'])) {
                    $group = $safe['group'];
                    $safe['group'] = array_intersect_key($group, array_flip(['id', 'title', 'kind', 'media_asset']));
                    if (($group['kind'] ?? '') === 'passage' || ($group['metadata']['show_transcript'] ?? false)) $safe['group']['content'] = $group['content'] ?? null;
                    $safe['group']['metadata'] = array_intersect_key($group['metadata'] ?? [], array_flip(['max_plays', 'autoplay', 'allow_replay', 'duration_seconds', 'attribution', 'source_url', 'license_url', 'source_label']));
                    if (! empty($safe['group']['media_asset'])) $safe['group']['media_asset'] = array_intersect_key($safe['group']['media_asset'], array_flip(['id', 'url', 'mime', 'kind', 'original_name']));
                }
                return $safe;
            }, $section['questions']);
            $section['locked'] = ($snapshot['rules']['lock_sections'] ?? true) && (now()->lt(Carbon::parse($section['starts_at'])) || now()->gte(Carbon::parse($section['ends_at'])));
            $sections[] = $section;
        }
        $data = $attempt->toArray();
        $data['remaining_seconds'] = $attempt->expires_at ? max(0, (int) now()->diffInSeconds($attempt->expires_at, false)) : null;
        $data['package'] = $snapshot['package'];
        if (isset($data['package']['settings']['access_code'])) unset($data['package']['settings']['access_code']);
        return ['attempt' => $data, 'sections' => $sections, 'answers' => $attempt->answers()->get()->keyBy('question_key'), 'server_time' => now()->toISOString(), 'rules' => $snapshot['rules']];
    }

    /** Release explanations only after finalization and only under the captured review policy. */
    public function review(ExamAttempt $attempt): array
    {
        if ($attempt->status !== 'completed' || ! ($attempt->snapshot['rules']['show_review'] ?? false)) return [];
        $answers = $attempt->answers()->get()->keyBy('question_key');
        $scores = collect($attempt->result['question_scores'] ?? [])->keyBy('key');
        $review = [];
        foreach ($attempt->snapshot['sections'] ?? [] as $section) foreach ($section['questions'] as $question) {
            if ($question['confidential'] ?? false) continue;
            $answer = $answers->get($question['key']);
            $review[] = ['key' => $question['key'], 'question_id' => $question['id'], 'type' => $question['type'], 'prompt' => $question['prompt'] ?? '', 'options' => $question['options'] ?? [], 'metadata' => $this->publicMetadata($question['metadata'] ?? []), 'response' => $answer?->response, 'answer_schema' => $question['answer_schema'] ?? [], 'explanation' => $question['explanation'] ?? null, 'score' => $scores->get($question['key'])['score'] ?? null, 'feedback' => $answer?->feedback];
        }

        return $review;
    }

    private function publicMetadata(array $metadata): array
    {
        $metadata = array_intersect_key($metadata, array_flip(['left_items', 'right_items', 'matches', 'right_options', 'items', 'blanks', 'labels', 'min_words', 'max_words', 'max_length', 'preparation_seconds', 'recording_seconds', 'max_recordings', 'max_retries', 'max_duration_seconds', 'response_seconds', 'rubric', 'media_kind', 'media_url', 'media_asset_id', 'image_url', 'audio_url', 'video_url', 'alt', 'source_label', 'stimulus', 'layout', 'manual', 'note', 'max_plays', 'play_limit', 'autoplay', 'allow_replay', 'play_duration_seconds']));
        return $this->redactKeys($metadata);
    }

    private function redactKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['correct', 'accepted', 'answer', 'answer_schema', 'correct_answer', 'is_correct', 'match_id', 'solution', 'explanation', 'transcript'], true)) { unset($data[$key]); continue; }
            if (is_array($value)) $data[$key] = $this->redactKeys($value);
        }
        return $data;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['attempt' => $message]);
    }
}
