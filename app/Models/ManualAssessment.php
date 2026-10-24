<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ManualAssessment extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['scores' => 'array', 'score' => 'float']; }
    public function attempt() { return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id'); }
    public function answer() { return $this->belongsTo(ExamAnswer::class, 'exam_answer_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewer_id')->withTrashed(); }
}
