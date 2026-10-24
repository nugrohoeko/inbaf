<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Certificate, Competency, ExamAnswer, ExamAttempt, ExamPackage, ExamSection, ManualAssessment, MediaAsset, ProficiencyLevel, Question, QuestionGroup, QuestionType, QuestionVersion, SystemSetting, User};
use App\Services\ScoringService;
use App\Support\{Audit, RichText};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private const RESOURCES = [
        'questions' => Question::class, 'packages' => ExamPackage::class,
        'participants' => User::class, 'competencies' => Competency::class,
        'groups' => QuestionGroup::class, 'media' => MediaAsset::class,
        'reviews' => ExamAnswer::class, 'certificates' => Certificate::class,
        'levels' => ProficiencyLevel::class, 'audit' => AuditLog::class,
        'attempts' => ExamAttempt::class,
    ];

    private function query(string $resource)
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $query = (self::RESOURCES[$resource])::query();
        if ($resource === 'participants') $query->where('role', 'participant')->with('profile');
        if ($resource === 'questions') $query->with(['competency', 'group']);
        if ($resource === 'packages') $query->with('sections.competency');
        if ($resource === 'groups') $query->with('mediaAsset');
        if ($resource === 'certificates') $query->with('user');
        if ($resource === 'attempts') $query->with(['user', 'package']);
        if ($resource === 'reviews') $query->where('requires_manual', true)->whereNull('manual_score')->whereHas('attempt', fn ($q) => $q->where('status', 'pending_review'))->with('attempt.user');
        return $query;
    }

    public function index(Request $request, string $resource)
    {
        $query = $this->query($resource);
        if ($search = $request->string('search')->toString()) {
            $columns = match ($resource) {
                'participants' => ['name', 'email'], 'questions' => ['title', 'code', 'prompt'],
                'packages', 'competencies', 'levels' => ['name', 'code'], 'groups' => ['title'],
                'media' => ['original_name'], 'certificates' => ['number'], 'audit' => ['event'], default => [],
            };
            if ($columns) $query->where(function ($q) use ($columns, $search) {
                foreach ($columns as $column) $q->orWhere($column, 'like', '%'.addcslashes($search, '%_\\').'%');
            });
        }
        if ($resource === 'questions') {
            foreach (['status', 'type', 'competency_id', 'difficulty'] as $field) {
                if ($request->filled($field)) $query->where($field, $request->input($field));
            }
            if ($request->filled('test_type')) $query->whereJsonContains('allowed_test_types', $request->input('test_type'));
        }
        if (in_array($resource, ['packages', 'attempts'], true) && $request->filled('test_type')) $query->where('test_type', $request->input('test_type'));
        $rows = $query->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));
        if ($resource === 'reviews') $rows->getCollection()->transform(function ($answer) {
            $attempt = $answer->attempt;
            $question = collect($attempt->snapshot['sections'] ?? [])->flatMap(fn ($s) => $s['questions'] ?? [])->firstWhere('key', $answer->question_key);
            $answer->setAttribute('question', $question);
            return $answer;
        });
        return $rows;
    }

    public function show(string $resource, string $id)
    {
        $model = $this->query($resource)->findOrFail($id);
        if ($resource === 'questions') $model->setAttribute('versions', QuestionVersion::where('question_id', $id)->orderByDesc('version')->get());
        if ($resource === 'packages') $model->setAttribute('effective_policy', $model->effectivePolicy());
        return $model;
    }

    public function questionUsage(Request $request, string $id)
    {
        Question::findOrFail($id);
        $rows = \App\Models\QuestionUsage::where('question_id', $id)->with(['attempt.user', 'attempt.package'])->latest('id')->paginate(20);
        $rows->getCollection()->transform(fn ($usage) => [
            'id' => $usage->id, 'attempt_id' => $usage->exam_attempt_id, 'exam_attempt_id' => $usage->exam_attempt_id,
            'test_type' => $usage->test_type, 'question_version' => $usage->question_version,
            'used_at' => $usage->used_at, 'created_at' => $usage->created_at,
            'package_name' => $usage->attempt?->snapshot['package']['name'] ?? $usage->attempt?->package?->name,
            'participant_name' => $usage->attempt?->snapshot['participant_name'] ?? $usage->attempt?->user?->name,
        ]);
        return $rows;
    }

    public function store(Request $request, string $resource)
    {
        return $this->write($request, $resource);
    }

    public function update(Request $request, string $resource, string $id)
    {
        return $this->write($request, $resource, $id);
    }

    private function validateData(array $input, string $resource, ?string $id): array
    {
        $rules = match ($resource) {
            'questions' => [
                'code' => ['nullable', 'string', 'max:60', Rule::unique('questions')->ignore($id)],
                'title' => 'required|string|max:255', 'competency_id' => 'required|exists:competencies,id',
                'question_group_id' => 'nullable|exists:question_groups,id', 'type' => 'required|exists:question_types,code',
                'prompt' => 'required|string|max:50000', 'instructions' => 'nullable|string|max:10000',
                'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard', 'beginner', 'intermediate', 'advanced'])],
                'weight' => 'required|numeric|min:0.01|max:1000', 'status' => ['required', Rule::in(['draft', 'review', 'active', 'archived'])],
                'options' => 'present|array|max:100', 'options.*.id' => 'required|string|max:100|distinct',
                'options.*.text' => 'required|string|max:5000', 'answer_schema' => 'present|array',
                'metadata' => 'nullable|array', 'explanation' => 'nullable|string|max:20000',
                'duration_seconds' => 'nullable|integer|min:1|max:7200',
                'allowed_test_types' => 'sometimes|required|array|min:1|max:3',
                'allowed_test_types.*' => ['required', 'distinct', Rule::in(['practice', 'placement', 'certification'])],
                'confidential' => 'sometimes|boolean', 'usage_limit' => 'nullable|integer|min:1|max:10000000',
            ],
            'packages' => [
                'code' => ['required', 'string', 'max:60', Rule::unique('exam_packages')->ignore($id)],
                'name' => 'required|string|max:255', 'description' => 'nullable|string|max:10000',
                'published' => 'required|boolean', 'duration_minutes' => 'required|integer|min:1|max:1440',
                'attempts_limit' => 'required|integer|min:0|max:10000', 'pass_score' => 'required|numeric|min:0|max:100',
                'test_type' => ['sometimes', Rule::in(['practice', 'placement', 'certification'])],
                'policy_overrides' => 'sometimes|nullable|array',
                'certificate_enabled' => 'required|boolean', 'question_selection' => ['required', Rule::in(['random', 'manual'])],
                'settings' => 'nullable|array', 'sections' => 'required|array|min:1|max:30',
                'settings.starts_at' => 'nullable|date', 'settings.ends_at' => 'nullable|date'.(!empty($input['settings']['starts_at']) ? '|after:settings.starts_at' : ''),
                'settings.lock_sections' => 'sometimes|boolean', 'settings.randomize_options' => 'sometimes|boolean',
                'settings.show_review' => 'sometimes|boolean',
                'sections.*.competency_id' => 'required|exists:competencies,id', 'sections.*.title' => 'required|string|max:255',
                'sections.*.duration_minutes' => 'required|integer|min:1|max:1440',
                'sections.*.weight' => 'required|numeric|min:0.01|max:1000', 'sections.*.question_count' => 'required|integer|min:1|max:500',
                'sections.*.question_ids' => 'nullable|array|max:500', 'sections.*.question_ids.*' => 'integer|exists:questions,id|distinct',
                'sections.*.randomize' => 'required|boolean',
                'sections.*.difficulties' => 'sometimes|nullable|array|max:6',
                'sections.*.difficulties.*' => ['required', 'distinct', Rule::in(['easy', 'medium', 'hard', 'beginner', 'intermediate', 'advanced'])],
            ],
            'participants' => [
                'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
                'active' => 'required|boolean', 'password' => ($id ? 'nullable' : 'required').'|string|min:12|max:128',
                'profile' => 'nullable|array', 'profile.country' => 'nullable|string|max:100',
                'profile.nationality' => 'nullable|string|max:100', 'profile.native_language' => 'nullable|string|max:100',
                'profile.institution' => 'nullable|string|max:255',
            ],
            'competencies' => [
                'code' => ['required', 'string', 'max:60', Rule::unique('competencies')->ignore($id)],
                'name' => 'required|string|max:255', 'name_en' => 'required|string|max:255', 'description' => 'nullable|string|max:5000',
                'icon' => 'nullable|string|max:60', 'color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
                'sort_order' => 'required|integer|min:0|max:999', 'active' => 'required|boolean',
            ],
            'groups' => [
                'title' => 'required|string|max:255', 'kind' => ['required', Rule::in(['passage', 'audio', 'video', 'image'])],
                'content' => 'nullable|string|max:100000', 'media_asset_id' => 'nullable|exists:media_assets,id', 'metadata' => 'nullable|array',
            ],
            'levels' => [
                'code' => ['required', 'string', 'max:20', Rule::unique('proficiency_levels')->ignore($id)],
                'name' => 'required|string|max:255', 'min_score' => ['required', 'numeric', 'min:0', 'max:100', Rule::unique('proficiency_levels', 'min_score')->ignore($id)],
                'description' => 'nullable|string|max:10000', 'sort_order' => 'required|integer|min:0|max:1000',
            ],
            default => abort(405),
        };
        if ($resource === 'packages') $rules = array_merge($rules, TestTypeController::policyRules('policy_overrides.', true));
        $data = Validator::make($input, $rules)->validate();
        if ($resource === 'questions') {
            $existing = $id ? Question::findOrFail($id) : null;
            $types = $data['allowed_test_types'] ?? $existing?->allowed_test_types ?? ['certification'];
            $confidential = $data['confidential'] ?? $existing?->confidential ?? false;
            if ($confidential && array_diff($types, ['certification'])) {
                throw ValidationException::withMessages(['allowed_test_types' => 'Soal rahasia hanya boleh digunakan untuk tes sertifikasi.']);
            }
            $data['allowed_test_types'] = $types;
        }
        if ($resource === 'levels' && (float)$data['min_score'] > 0 && !ProficiencyLevel::where('min_score', 0)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['min_score' => 'Keep one proficiency level starting at zero so every result can be classified.']);
        }
        foreach (['prompt', 'instructions', 'explanation', 'content', 'description'] as $field) {
            if (isset($data[$field])) $data[$field] = RichText::clean($data[$field]);
        }
        if ($resource === 'questions' && $data['status'] === 'active') $this->validateAnswerSchema($data);
        if ($resource === 'packages') {
            $data['test_type'] ??= $id ? ExamPackage::findOrFail($id)->test_type : 'certification';
            if (! $id && !array_key_exists('policy_overrides', $data)) $data['policy_overrides'] = [];
            TestTypeController::validateThresholds($data['policy_overrides']['level_thresholds'] ?? null, 'policy_overrides.level_thresholds');
            if ($data['test_type'] !== 'certification') $data['certificate_enabled'] = false;
            if (array_sum(array_column($data['sections'], 'duration_minutes')) > $data['duration_minutes']) {
                throw ValidationException::withMessages(['duration_minutes' => 'Total duration must cover all sections.']);
            }
            foreach ($data['sections'] as $i => $section) {
                $available = Question::where('competency_id', $section['competency_id'])->where('status', 'active');
                $available->whereJsonContains('allowed_test_types', $data['test_type']);
                if ($data['test_type'] !== 'certification') $available->where('confidential', false);
                $available->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'));
                if (!empty($section['difficulties'])) $available->whereIn('difficulty', $section['difficulties']);
                if ($data['question_selection'] === 'manual') {
                    if (empty($section['question_ids'])) throw ValidationException::withMessages(["sections.{$i}.question_ids" => 'Pilih soal untuk sesi yang menggunakan pemilihan manual.']);
                    $available->whereIn('id', $section['question_ids']);
                    if ($data['published'] && $available->count() !== count(array_unique($section['question_ids']))) throw ValidationException::withMessages(["sections.{$i}.question_ids" => 'Seluruh soal pilihan harus aktif, diizinkan, sesuai tingkat kesulitan, dan belum mencapai batas penggunaan.']);
                }
                if ($data['published'] && $available->count() < $section['question_count']) {
                    throw ValidationException::withMessages(["sections.{$i}.question_count" => 'Not enough active questions in this competency.']);
                }
            }
        }
        return $data;
    }

    private function validateAnswerSchema(array $data): void
    {
        if (in_array($data['type'], ['essay', 'speaking']) || ($data['metadata']['manual'] ?? false)) return;
        $correct = $data['answer_schema']['correct'] ?? null;
        if (($correct === null || $correct === '' || $correct === []) && empty($data['answer_schema']['accepted'])) {
            throw ValidationException::withMessages(['answer_schema' => 'An active objective question needs a correct answer.']);
        }
        if (in_array($data['type'], ['single_choice', 'listening', 'reading', 'image', 'video', 'error_identification', 'conversation']) || ($data['type'] === 'dropdown' && empty($data['metadata']['blanks']))) {
            if (!is_scalar($correct) || count($data['options']) < 2 || ! in_array((string) $correct, array_column($data['options'], 'id'), true)) {
                throw ValidationException::withMessages(['answer_schema' => 'Choose a correct option from at least two options.']);
            }
        }
        if ($data['type'] === 'multiple_response' && (! is_array($correct) || count($data['options']) < 2 || array_diff($correct, array_column($data['options'], 'id')))) {
            throw ValidationException::withMessages(['answer_schema' => 'Correct responses must reference option IDs.']);
        }
        if ($data['type'] === 'true_false' && !in_array($correct, [true, false, 'true', 'false'], true)) {
            throw ValidationException::withMessages(['answer_schema' => 'The correct answer must be true or false.']);
        }
        if (in_array($data['type'], ['sentence_ordering', 'word_ordering'])) {
            $ids = array_column($data['options'], 'id');
            if (!is_array($correct) || !array_is_list($correct) || count($ids) < 2 || count($correct) !== count($ids) || count(array_unique($correct)) !== count($ids) || array_diff($correct, $ids)) {
                throw ValidationException::withMessages(['answer_schema' => 'The ordered answer must include every option ID exactly once.']);
            }
        }
        if (in_array($data['type'], ['matching', 'drag_drop'])) {
            $left = array_column($data['metadata']['left_items'] ?? [], 'id');
            $right = array_column($data['metadata']['right_items'] ?? [], 'id');
            if (!$left || !$right || !is_array($correct) || count($correct) !== count($left) || array_diff(array_map('strval', array_keys($correct)), array_map('strval', $left)) || array_diff(array_map('strval', array_values($correct)), array_map('strval', $right))) {
                throw ValidationException::withMessages(['answer_schema' => 'Create left and right items and map every left item to a valid right item.']);
            }
        }
        if (in_array($data['type'], ['dropdown', 'fill_blank']) && !empty($data['metadata']['blanks'])) {
            $blanks = $data['metadata']['blanks'];
            if (!is_array($correct) || count($correct) !== count($blanks)) throw ValidationException::withMessages(['answer_schema' => 'Provide the answer for each blank.']);
            foreach ($blanks as $blank) {
                $answer = $correct[$blank['id'] ?? ''] ?? null;
                if ($answer === null || $answer === '' || ($data['type'] === 'dropdown' && !in_array((string)$answer, array_map('strval', array_column($blank['options'] ?? [], 'id')), true))) {
                    throw ValidationException::withMessages(['answer_schema' => 'Each blank needs a valid answer from its available options.']);
                }
            }
        }
        if (in_array($data['type'], ['listening', 'image', 'video'])) {
            $group = !empty($data['question_group_id']) ? QuestionGroup::with('mediaAsset')->find($data['question_group_id']) : null;
            $asset = $group?->mediaAsset ?? MediaAsset::find($data['metadata']['media_asset_id'] ?? 0);
            $expected = ['listening' => 'audio', 'image' => 'image', 'video' => 'video'][$data['type']];
            if (!$asset || $asset->kind !== $expected) throw ValidationException::withMessages(['question_group_id' => 'Attach valid '.$expected.' material before activating this question.']);
        }
    }

    private function write(Request $request, string $resource, ?string $id = null)
    {
        if ($resource === 'participants') return app(UserManagementController::class)->legacyWrite($request, $id);
        $data = $this->validateData($request->all(), $resource, $id);
        $model = DB::transaction(function () use ($resource, $id, $data) {
            $model = $id ? $this->query($resource)->lockForUpdate()->findOrFail($id) : new (self::RESOURCES[$resource]);
            $sections = $data['sections'] ?? null; unset($data['sections']);
            $profile = $data['profile'] ?? null; unset($data['profile']);
            if ($resource === 'questions') {
                $data['code'] = ($data['code'] ?? '') ?: 'Q-'.Str::upper(Str::random(10));
                $data['version'] = $id ? $model->version + 1 : 1;
            }
            if ($resource === 'participants') {
                $data['role'] = 'participant';
                if (!empty($data['password'])) $data['password'] = Hash::make($data['password']); else unset($data['password']);
            }
            $model->fill($data)->save();
            if ($resource === 'questions') QuestionVersion::create(['question_id' => $model->id, 'version' => $model->version, 'snapshot' => $model->fresh()->toArray()]);
            if ($sections !== null) {
                $model->sections()->delete();
                foreach ($sections as $position => $section) {
                    unset($section['id']); $section['position'] = $position;
                    $model->sections()->create($section);
                }
            }
            if ($profile !== null) $model->profile()->updateOrCreate(['user_id' => $model->id], $profile);
            Audit::record($resource.'.'.($id ? 'updated' : 'created'), $model, ['fields' => array_keys($data)]);
            return $model;
        });
        return response()->json($this->query($resource)->findOrFail($model->id), $id ? 200 : 201);
    }

    public function destroy(string $resource, string $id)
    {
        if ($resource === 'participants') return app(UserManagementController::class)->legacyDeactivate(request(), $id);
        abort_unless(in_array($resource, ['questions', 'packages', 'participants', 'competencies', 'groups']), 405);
        $model = $this->query($resource)->findOrFail($id);
        DB::transaction(function () use ($resource, $model) {
            match ($resource) {
                'questions' => $model->update(['status' => 'archived', 'version' => $model->version + 1]),
                'packages' => $model->update(['published' => false]),
                'participants', 'competencies' => $model->update(['active' => false]),
                'groups' => $model->delete(),
            };
            if ($resource === 'questions') QuestionVersion::create(['question_id' => $model->id, 'version' => $model->version, 'snapshot' => $model->fresh()->toArray()]);
            Audit::record($resource.'.archived', $model);
        });
        return response()->json(['message' => 'Archived successfully.']);
    }

    public function duplicate(string $id)
    {
        $question = Question::findOrFail($id);
        $copy = DB::transaction(function () use ($question) {
            $copy = $question->replicate();
            $copy->code = 'Q-'.Str::upper(Str::random(10)); $copy->title .= ' (copy)';
            $copy->status = 'draft'; $copy->version = 1; $copy->usage_count = 0; $copy->save();
            QuestionVersion::create(['question_id' => $copy->id, 'version' => 1, 'snapshot' => $copy->toArray()]);
            Audit::record('questions.duplicated', $copy, ['source_id' => $question->id]);
            return $copy;
        });
        return response()->json($copy, 201);
    }

    public function bulkQuestions(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|exists:questions,id', 'action' => ['required', Rule::in(['draft', 'review', 'active', 'archived'])]]);
        DB::transaction(function () use ($data) {
            foreach (Question::whereIn('id', $data['ids'])->lockForUpdate()->get() as $q) {
                if ($data['action'] === 'active') $this->validateAnswerSchema($q->toArray());
                $q->update(['status' => $data['action'], 'version' => $q->version + 1]);
                QuestionVersion::create(['question_id' => $q->id, 'version' => $q->version, 'snapshot' => $q->fresh()->toArray()]);
                Audit::record('questions.status_changed', $q, ['status' => $data['action']]);
            }
        });
        return ['message' => 'Questions updated.'];
    }

    public function assess(Request $request, string $id)
    {
        $data = $request->validate(['score' => 'required|numeric|min:0|max:100', 'feedback' => 'required|string|max:10000', 'scores' => 'nullable|array', 'scores.*' => 'numeric|min:0|max:100']);
        return DB::transaction(function () use ($request, $id, $data) {
            $answer = ExamAnswer::findOrFail($id);
            $attempt = ExamAttempt::lockForUpdate()->findOrFail($answer->exam_attempt_id);
            abort_unless($attempt->status === 'pending_review', 409, 'Only pending attempts can be reviewed.');
            $question = collect($attempt->snapshot['sections'] ?? [])->flatMap(fn ($s) => $s['questions'] ?? [])->firstWhere('key', $answer->question_key);
            abort_unless($question && (in_array($question['type'], ['essay', 'speaking']) || ($question['metadata']['manual'] ?? false)), 422, 'This answer is scored automatically.');
            $answer->update(['manual_score' => $data['score'], 'feedback' => $data['feedback']]);
            ManualAssessment::create(['exam_attempt_id' => $attempt->id, 'exam_answer_id' => $answer->id, 'reviewer_id' => $request->user()->id, 'scores' => $data['scores'] ?? [], 'score' => $data['score'], 'feedback' => $data['feedback']]);
            Audit::record('answer.assessed', $answer, ['score' => $data['score']]);
            app(ScoringService::class)->finalize($attempt);
            return ['message' => 'Assessment saved.', 'attempt' => $attempt->fresh()];
        });
    }

    private const SETTING_RULES = [
        'system_name' => 'string|max:100', 'tagline' => 'string|max:255',
        'institution_name' => 'string|max:255', 'institution_address' => 'nullable|string|max:1000',
        'contact_email' => 'nullable|email|max:255', 'footer' => 'nullable|string|max:1000',
        'primary_color' => 'regex:/^#[0-9A-Fa-f]{6}$/', 'default_locale' => 'in:en,id',
        'registration_enabled' => 'boolean', 'score_scale' => 'numeric|min:1|max:10000',
        'certificate_validity_months' => 'nullable|integer|min:0|max:120',
        'certificate_template' => 'in:modern,formal', 'signer_name' => 'nullable|string|max:255',
        'certificate_paper' => 'in:a4,letter', 'certificate_orientation' => 'in:landscape,portrait',
        'certificate_background_media_id' => 'nullable|integer|exists:media_assets,id',
        'certificate_signature_media_id' => 'nullable|integer|exists:media_assets,id',
        'signer_title' => 'nullable|string|max:255', 'certificate_signer_name' => 'nullable|string|max:255',
        'certificate_signer_title' => 'nullable|string|max:255',
        'logo_media_id' => 'nullable|integer|exists:media_assets,id', 'favicon_media_id' => 'nullable|integer|exists:media_assets,id',
        'require_fullscreen' => 'boolean', 'tab_switch_limit' => 'integer|min:0|max:100',
        'session_policy' => 'in:sequential,flexible', 'retention_days' => 'integer|min:30|max:3650',
        'email_notifications_enabled' => 'boolean', 'ai_enabled' => 'boolean',
        'storage_driver' => 'in:local', 'max_upload_mb' => 'integer|min:1|max:50',
    ];

    public function settings()
    {
        return SystemSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(self::SETTING_RULES);
        foreach (['logo_media_id', 'favicon_media_id', 'certificate_background_media_id', 'certificate_signature_media_id'] as $field) {
            if (!empty($data[$field])) {
                $media = MediaAsset::findOrFail($data[$field]);
                if ($media->kind !== 'image' || !in_array($media->mime, ['image/png', 'image/jpeg', 'image/webp'])) {
                    throw ValidationException::withMessages([$field => 'Select a PNG, JPEG, or WebP image from the media library.']);
                }
                if (!app(\App\Services\CertificateService::class)->imageAsset($media->path)) {
                    throw ValidationException::withMessages([$field => 'Select a valid private image no larger than 10 MB or 24 megapixels.']);
                }
            }
        }
        if (($data['ai_enabled'] ?? false) || ($data['email_notifications_enabled'] ?? false)) {
            throw ValidationException::withMessages(['integrations' => 'Configure and verify an integration provider before enabling this feature.']);
        }
        DB::transaction(function () use ($data) {
            foreach ($data as $key => $value) SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
            Audit::record('settings.updated', null, ['keys' => array_keys($data)]);
        });
        return $this->settings();
    }

    private const LEGACY_CSV_COLUMNS = ['code', 'title', 'competency_id', 'type', 'difficulty', 'weight', 'status', 'prompt', 'instructions', 'options', 'answer_schema', 'metadata', 'question_group_id'];
    private const CSV_COLUMNS = [...self::LEGACY_CSV_COLUMNS, 'allowed_test_types', 'confidential', 'usage_limit', 'duration_seconds', 'explanation'];

    public function template(Request $request)
    {
        $rows = [array_combine(self::CSV_COLUMNS, ['','Example question',Competency::value('id') ?? 1,'single_choice','easy',1,'draft','Pilih kalimat yang benar.','','[{"id":"a","text":"Saya belajar."},{"id":"b","text":"Saya belajar di."}]','{"correct":"a"}','{}','','["practice","placement"]',0,'','',''])];
        return $request->input('format') === 'xlsx' ? $this->xlsxResponse('inbaf-question-template.xlsx', $rows) : $this->csvResponse('inbaf-question-template.csv', $rows);
    }

    public function exportQuestions(Request $request)
    {
        $rows = Question::orderBy('id')->get()->map(fn ($q) => collect($q->toArray())->only(self::CSV_COLUMNS)->all());
        Audit::record('questions.exported', null, ['count' => $rows->count()]);
        return $request->input('format') === 'xlsx' ? $this->xlsxResponse('inbaf-questions.xlsx', $rows) : $this->csvResponse('inbaf-questions.csv', $rows);
    }

    private function xlsxResponse(string $name, iterable $rows)
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet(); $sheet->setTitle('INBAF Questions');
        $sheet->fromArray(self::CSV_COLUMNS, null, 'A1');
        $index = 2;
        foreach ($rows as $row) {
            foreach (self::CSV_COLUMNS as $i => $column) {
                $value = $row[$column] ?? '';
                if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                $sheet->setCellValueExplicit([$i + 1, $index], (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $index++;
        }
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(self::CSV_COLUMNS));
        $sheet->freezePane('A2'); $sheet->setAutoFilter('A1:'.$lastColumn.max(1, $index-1));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        foreach (range('A', $lastColumn) as $column) $sheet->getColumnDimension($column)->setWidth(in_array($column, ['H','J','K','L','R']) ? 45 : 22);
        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function csvResponse(string $name, iterable $rows)
    {
        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, self::CSV_COLUMNS, ',', '"', '');
            foreach ($rows as $row) {
                $values = [];
                foreach (self::CSV_COLUMNS as $column) {
                    $value = $row[$column] ?? '';
                    if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    // Prevent spreadsheet formula execution when opened by a reviewer.
                    if (is_string($value) && preg_match('/^[=+@\-\t\r]/', $value)) $value = "'".$value;
                    $values[] = $value;
                }
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importQuestions(Request $request)
    {
        $request->validate(['file' => 'required|file|max:5120|mimes:csv,txt,xlsx']);
        $file = $request->file('file');
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            try {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                $reader->setReadDataOnly(true);
                $info = $reader->listWorksheetInfo($file->getRealPath())[0] ?? null;
                if (!$info || $info['totalRows'] > 501 || $info['totalColumns'] > count(self::CSV_COLUMNS)) {
                    throw new \RuntimeException('Use one template sheet containing at most 500 question rows.');
                }
                $reader->setLoadSheetsOnly($info['worksheetName']);
                $book = $reader->load($file->getRealPath());
                $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($info['totalColumns']);
                $sheetRows = $book->getSheet(0)->rangeToArray('A1:'.$lastColumn.max(1,$info['totalRows']), '', false, false);
                $stream = fopen('php://temp', 'w+');
                foreach ($sheetRows as $row) fputcsv($stream, $row, ',', '"', '');
                rewind($stream); $book->disconnectWorksheets();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['file' => 'The Excel file cannot be read. Use the INBAF template and at most 500 rows.']);
            }
        } else {
            $stream = fopen($file->getRealPath(), 'r');
        }
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if ($headers) $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        if ($headers !== self::CSV_COLUMNS && $headers !== self::LEGACY_CSV_COLUMNS) throw ValidationException::withMessages(['file' => 'Use the downloadable CSV template without changing columns.']);
        $rows = []; $errors = []; $line = 1; $seenCodes = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if ($line > 501) { $errors['file'] = ['A maximum of 500 rows may be imported.']; break; }
            if ($row === [null]) continue;
            if (count($row) !== count($headers)) { $errors["row_{$line}"] = ['Incorrect number of columns.']; continue; }
            $data = array_combine($headers, $row);
            if ($data['code'] !== '') {
                if (isset($seenCodes[$data['code']])) { $errors["row_{$line}"] = ['Duplicate question code in this import.']; continue; }
                $seenCodes[$data['code']] = true;
            }
            foreach (['options', 'answer_schema', 'metadata'] as $column) $data[$column] = json_decode($data[$column] ?: '[]', true);
            if (array_key_exists('allowed_test_types', $data)) $data['allowed_test_types'] = json_decode($data['allowed_test_types'] ?: '["certification"]', true);
            foreach (['usage_limit', 'duration_seconds'] as $column) if (array_key_exists($column, $data) && $data[$column] === '') $data[$column] = null;
            $data['question_group_id'] = $data['question_group_id'] ?: null;
            try { $rows[] = $this->validateData($data, 'questions', null); }
            catch (ValidationException $e) { $errors["row_{$line}"] = collect($e->errors())->flatten()->all(); }
        }
        fclose($stream);
        if ($errors) throw ValidationException::withMessages($errors);
        DB::transaction(function () use ($rows) {
            foreach ($rows as $data) {
                $data['code'] = $data['code'] ?: 'Q-'.Str::upper(Str::random(10)); $data['version'] = 1;
                $question = Question::create($data);
                QuestionVersion::create(['question_id' => $question->id, 'version' => 1, 'snapshot' => $question->toArray()]);
            }
            Audit::record('questions.imported', null, ['count' => count($rows)]);
        });
        return response()->json(['message' => count($rows).' questions imported.', 'count' => count($rows)], 201);
    }
}
