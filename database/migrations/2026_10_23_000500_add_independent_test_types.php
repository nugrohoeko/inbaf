<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_types', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->string('name_en');
            $table->text('description')->nullable(); $table->text('description_en')->nullable();
            $table->boolean('active')->default(true); $table->boolean('timer_enabled')->default(true);
            $table->boolean('security_monitoring')->default(false); $table->boolean('require_fullscreen')->default(false);
            $table->unsignedInteger('tab_switch_limit')->default(3); $table->boolean('lock_sections')->default(false);
            $table->unsignedInteger('attempts_limit')->default(0); $table->boolean('show_review')->default(false);
            $table->boolean('randomize_options')->default(true); $table->decimal('score_scale', 9, 2)->default(100);
            $table->string('scoring_method')->default('weighted_competency'); $table->json('level_thresholds');
            $table->decimal('recommendation_threshold', 5, 2)->default(60); $table->timestamps();
        });
        $levels = DB::table('proficiency_levels')->orderBy('min_score')->get()->map(fn ($level) => ['code' => $level->code, 'name' => $level->name, 'min_score' => (float) $level->min_score])->all();
        if (! $levels) $levels = array_map(fn ($row) => ['code' => $row[0], 'name' => $row[1], 'min_score' => $row[2]], [['A1', 'Beginner', 0], ['A2', 'Elementary', 20], ['B1', 'Intermediate', 40], ['B2', 'Upper Intermediate', 60], ['C1', 'Advanced', 75], ['C2', 'Proficient', 90]]);
        $global = DB::table('system_settings')->pluck('value', 'key')->map(fn ($value) => json_decode($value, true))->all();
        foreach ([
            ['practice', 'Tes Simulasi', 'Practice Test', 'Berlatih dan pahami bentuk soal INBAF.', 'Practise and become familiar with INBAF questions.', false, 0, true],
            ['placement', 'Tes Penempatan', 'Placement Test', 'Kenali tingkat awal dan kebutuhan belajar Anda.', 'Discover your starting level and learning needs.', true, 3, false],
            ['certification', 'Tes Sertifikasi', 'Certification Test', 'Ujian kompetensi formal dan sertifikat lembaga INBAF.', 'Formal assessment and an institution-issued INBAF certificate.', true, 3, false],
        ] as [$code, $name, $english, $description, $descriptionEn, $timer, $limit, $review]) {
            $certification = $code === 'certification';
            DB::table('test_types')->insert(['code' => $code, 'name' => $name, 'name_en' => $english, 'description' => $description, 'description_en' => $descriptionEn, 'active' => true, 'timer_enabled' => $timer, 'attempts_limit' => $limit, 'show_review' => $review, 'lock_sections' => $certification && (($global['session_policy'] ?? 'sequential') === 'sequential'), 'security_monitoring' => $certification ? ($global['security_monitoring'] ?? true) : false, 'require_fullscreen' => $certification ? ($global['require_fullscreen'] ?? true) : false, 'tab_switch_limit' => $global['tab_switch_limit'] ?? 3, 'score_scale' => $certification ? ($global['score_scale'] ?? 100) : 100, 'scoring_method' => $code === 'placement' ? 'weighted_questions' : 'weighted_competency', 'level_thresholds' => json_encode($levels), 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('exam_packages', function (Blueprint $table) {
            $table->string('test_type')->default('certification')->index(); $table->json('policy_overrides')->nullable();
        });
        Schema::table('exam_sections', fn (Blueprint $table) => $table->json('difficulties')->nullable());
        Schema::table('questions', function (Blueprint $table) {
            $table->json('allowed_test_types')->nullable(); $table->boolean('confidential')->default(false)->index();
            $table->unsignedInteger('usage_limit')->nullable(); $table->unsignedInteger('usage_count')->default(0);
        });
        // Never expose pre-existing question banks to open practice without an explicit grant.
        DB::table('questions')->update(['allowed_test_types' => json_encode(['certification'])]);
        DB::table('questions')->where('metadata->starter_content', true)->update(['allowed_test_types' => json_encode(['practice', 'placement', 'certification'])]);
        foreach (['INBAF-PLACEMENT' => 'placement', 'INBAF-DAILY' => 'practice'] as $code => $type) {
            DB::table('exam_packages')->where('code', $code)->where('settings->starter_content', true)->update(['test_type' => $type, 'certificate_enabled' => false, 'policy_overrides' => json_encode([])]);
        }
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->string('test_type')->default('certification')->index(); $table->timestamp('expires_at')->nullable()->change();
        });
        Schema::create('question_usages', function (Blueprint $table) {
            $table->id(); $table->foreignUuid('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question_key'); $table->string('test_type')->index(); $table->unsignedInteger('question_version');
            $table->timestamp('used_at'); $table->timestamps(); $table->unique(['exam_attempt_id', 'question_key']);
            $table->index(['question_id', 'test_type']);
        });
        // Preserve historical usage without changing any existing attempt snapshots or results.
        DB::table('exam_attempts')->orderBy('id')->chunk(100, function ($attempts) {
            foreach ($attempts as $attempt) {
                $snapshot = json_decode($attempt->snapshot, true) ?? [];
                foreach ($snapshot['sections'] ?? [] as $section) foreach ($section['questions'] ?? [] as $question) {
                    if (! isset($question['id'], $question['key'])) continue;
                    $questionId = DB::table('questions')->where('id', $question['id'])->value('id');
                    DB::table('question_usages')->insertOrIgnore(['exam_attempt_id' => $attempt->id, 'question_id' => $questionId, 'question_key' => $question['key'], 'test_type' => 'certification', 'question_version' => $question['version'] ?? 1, 'used_at' => $attempt->started_at, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        foreach (DB::table('question_usages')->whereNotNull('question_id')->selectRaw('question_id, COUNT(*) as total')->groupBy('question_id')->get() as $usage) {
            DB::table('questions')->where('id', $usage->question_id)->update(['usage_count' => $usage->total]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_usages');
        Schema::table('exam_attempts', function (Blueprint $table) { $table->dropIndex(['test_type']); $table->dropColumn('test_type'); });
        Schema::table('questions', function (Blueprint $table) { $table->dropIndex(['confidential']); $table->dropColumn(['allowed_test_types', 'confidential', 'usage_limit', 'usage_count']); });
        Schema::table('exam_sections', fn (Blueprint $table) => $table->dropColumn('difficulties'));
        Schema::table('exam_packages', function (Blueprint $table) { $table->dropIndex(['test_type']); $table->dropColumn(['test_type', 'policy_overrides']); });
        Schema::dropIfExists('test_types');
    }
};
