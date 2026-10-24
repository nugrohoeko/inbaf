<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionUsage extends Model
{
    protected $guarded = [];

    protected function casts(): array { return ['used_at' => 'datetime', 'question_version' => 'integer']; }

    public function question() { return $this->belongsTo(Question::class); }
    public function attempt() { return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id'); }
}
