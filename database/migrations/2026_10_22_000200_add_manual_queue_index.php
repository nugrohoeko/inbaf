<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->boolean('requires_manual')->default(false);
            $table->index(['requires_manual', 'manual_score', 'exam_attempt_id'], 'answers_manual_queue_index');
        });

        // Derive old flags from immutable attempt content, never the mutable question bank.
        DB::table('exam_attempts')->select(['id', 'snapshot'])->orderBy('id')->chunkById(100, function ($attempts) {
            foreach ($attempts as $attempt) {
                $snapshot = json_decode($attempt->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $keys = [];
                foreach ($snapshot['sections'] ?? [] as $section) {
                    foreach ($section['questions'] ?? [] as $question) {
                        if (in_array($question['type'] ?? '', ['essay', 'speaking'], true) || ($question['metadata']['manual'] ?? false)) $keys[] = $question['key'];
                    }
                }
                if ($keys) DB::table('exam_answers')->where('exam_attempt_id', $attempt->id)->whereIn('question_key', $keys)->update(['requires_manual' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropIndex('answers_manual_queue_index');
            $table->dropColumn('requires_manual');
        });
    }
};
