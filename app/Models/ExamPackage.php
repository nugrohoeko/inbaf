<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExamPackage extends Model
{
    protected $guarded = [];
    protected $attributes = ['test_type' => 'certification'];
    protected function casts(): array { return ['published' => 'boolean', 'certificate_enabled' => 'boolean', 'settings' => 'array', 'policy_overrides' => 'array', 'pass_score' => 'float', 'duration_minutes' => 'integer', 'attempts_limit' => 'integer']; }
    protected static function booted(): void { static::saving(function ($package) { if ($package->test_type !== 'certification') $package->certificate_enabled = false; }); }
    public function testType() { return $this->belongsTo(TestType::class, 'test_type', 'code'); }
    public function effectivePolicy(): array { return $this->testType()->firstOrFail()->effectivePolicy($this); }
    public function sections() { return $this->hasMany(ExamSection::class)->orderBy('position'); }
    public function attempts() { return $this->hasMany(ExamAttempt::class); }
}
