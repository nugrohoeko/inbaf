<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Question extends Model
{
    protected $guarded = [];
    protected $attributes = ['allowed_test_types' => '["certification"]'];
    protected function casts(): array { return ['options' => 'array', 'answer_schema' => 'array', 'metadata' => 'array', 'allowed_test_types' => 'array', 'confidential' => 'boolean', 'usage_limit' => 'integer', 'usage_count' => 'integer', 'weight' => 'float', 'version' => 'integer', 'duration_seconds' => 'integer']; }
    public function competency() { return $this->belongsTo(Competency::class); }
    public function group() { return $this->belongsTo(QuestionGroup::class, 'question_group_id'); }
    public function questionGroup() { return $this->group(); }
    public function versions() { return $this->hasMany(QuestionVersion::class); }
    public function usages() { return $this->hasMany(QuestionUsage::class); }
}
