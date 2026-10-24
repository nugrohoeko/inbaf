<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExamAnswer extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['response' => 'json', 'marked' => 'boolean', 'requires_manual' => 'boolean', 'manual_score' => 'float', 'question_started_at' => 'datetime', 'question_expires_at' => 'datetime']; }
    public function attempt() { return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id'); }
    public function question() { return $this->belongsTo(Question::class); }
    public function assessments() { return $this->hasMany(ManualAssessment::class); }
}
