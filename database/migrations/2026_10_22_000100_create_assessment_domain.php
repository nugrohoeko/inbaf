<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('participant')->index();
            $table->boolean('active')->default(true);
        });
        Schema::create('participant_profiles', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('nationality')->nullable(); $table->string('native_language')->nullable();
            $table->string('institution')->nullable(); $table->string('country')->nullable(); $table->timestamps();
        });
        Schema::create('competencies', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->string('name_en');
            $table->text('description')->nullable(); $table->string('icon')->default('book-open');
            $table->string('color')->default('#14b8a6'); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('proficiency_levels', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name');
            $table->decimal('min_score', 7, 2)->default(0); $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0); $table->timestamps();
        });
        Schema::create('question_types', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name');
            $table->boolean('automatic')->default(true); $table->timestamps();
        });
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id(); $table->string('original_name'); $table->string('path'); $table->string('mime');
            $table->unsignedBigInteger('size'); $table->string('kind'); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('question_groups', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->string('kind'); $table->longText('content')->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('questions', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->foreignId('competency_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index(); $table->string('title'); $table->text('instructions')->nullable();
            $table->longText('prompt'); $table->string('difficulty')->default('basic'); $table->decimal('weight', 8, 2)->default(1);
            $table->string('status')->default('draft')->index(); $table->unsignedInteger('version')->default(1);
            $table->json('options')->nullable(); $table->json('answer_schema')->nullable(); $table->json('metadata')->nullable();
            $table->text('explanation')->nullable(); $table->unsignedInteger('duration_seconds')->nullable(); $table->timestamps();
            $table->index(['competency_id', 'status']);
        });
        Schema::create('question_versions', function (Blueprint $table) {
            $table->id(); $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version'); $table->json('snapshot'); $table->timestamps(); $table->unique(['question_id', 'version']);
        });
        Schema::create('exam_packages', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->text('description')->nullable();
            $table->boolean('published')->default(false)->index(); $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('attempts_limit')->default(3); $table->decimal('pass_score', 7, 2)->default(60);
            $table->boolean('certificate_enabled')->default(true); $table->string('question_selection')->default('manual');
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id(); $table->foreignId('exam_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->restrictOnDelete(); $table->string('title');
            $table->unsignedInteger('position')->default(0); $table->unsignedInteger('duration_minutes')->default(10);
            $table->decimal('weight', 8, 2)->default(1); $table->unsignedInteger('question_count')->default(5);
            $table->json('question_ids')->nullable(); $table->boolean('randomize')->default(true); $table->timestamps();
        });
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('exam_package_id')->constrained()->restrictOnDelete(); $table->string('status')->default('in_progress')->index();
            $table->timestamp('started_at'); $table->timestamp('expires_at'); $table->timestamp('submitted_at')->nullable();
            $table->decimal('score', 7, 2)->nullable(); $table->string('level')->nullable(); $table->json('result')->nullable();
            $table->json('snapshot'); $table->timestamps(); $table->index(['user_id', 'exam_package_id']);
        });
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id(); $table->foreignUuid('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete(); $table->string('question_key');
            $table->json('response')->nullable(); $table->boolean('marked')->default(false); $table->decimal('manual_score', 7, 2)->nullable();
            $table->text('feedback')->nullable(); $table->timestamps(); $table->unique(['exam_attempt_id', 'question_key']);
        });
        Schema::create('manual_assessments', function (Blueprint $table) {
            $table->id(); $table->foreignUuid('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_answer_id')->constrained()->cascadeOnDelete(); $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->json('scores'); $table->decimal('score', 7, 2); $table->text('feedback')->nullable(); $table->timestamps();
        });
        Schema::create('certificates', function (Blueprint $table) {
            $table->id(); $table->foreignUuid('exam_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->string('number')->unique(); $table->string('token')->unique();
            $table->json('payload'); $table->text('signature'); $table->string('status')->default('valid');
            $table->timestamp('expires_at')->nullable(); $table->timestamp('issued_at'); $table->string('template')->default('modern');
            $table->text('revocation_reason')->nullable(); $table->timestamps();
        });
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id(); $table->string('key')->unique(); $table->json('value')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('event')->index();
            $table->string('subject_type')->nullable(); $table->string('subject_id')->nullable(); $table->json('data')->nullable();
            $table->string('ip', 45)->nullable(); $table->timestamps();
        });
        Schema::create('certificate_verifications', function (Blueprint $table) {
            $table->id(); $table->foreignId('certificate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token')->nullable(); $table->string('ip', 45)->nullable(); $table->boolean('valid')->default(false); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['certificate_verifications', 'audit_logs', 'system_settings', 'certificates', 'manual_assessments', 'exam_answers', 'exam_attempts', 'exam_sections', 'exam_packages', 'question_versions', 'questions', 'question_groups', 'media_assets', 'question_types', 'proficiency_levels', 'competencies', 'participant_profiles'] as $name) Schema::dropIfExists($name);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active']));
    }
};
