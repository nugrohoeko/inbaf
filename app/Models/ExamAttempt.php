<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class ExamAttempt extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['snapshot'];
    protected static function booted(): void { static::creating(function ($attempt) { if (! $attempt->test_type) $attempt->test_type = $attempt->snapshot['test_type'] ?? $attempt->snapshot['package']['test_type'] ?? ExamPackage::whereKey($attempt->exam_package_id)->value('test_type') ?? 'certification'; }); }
    protected function casts(): array { return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'score' => 'float', 'result' => 'array', 'snapshot' => 'array']; }
    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
    public function package() { return $this->belongsTo(ExamPackage::class, 'exam_package_id'); }
    public function examPackage() { return $this->package(); }
    public function answers() { return $this->hasMany(ExamAnswer::class); }
    public function certificate() { return $this->hasOne(Certificate::class); }
    public function assessments() { return $this->hasMany(ManualAssessment::class); }
    public function questionUsages() { return $this->hasMany(QuestionUsage::class); }
}
