<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExamSection extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['question_ids' => 'array', 'difficulties' => 'array', 'randomize' => 'boolean', 'weight' => 'float', 'duration_minutes' => 'integer', 'question_count' => 'integer', 'position' => 'integer']; }
    public function package() { return $this->belongsTo(ExamPackage::class, 'exam_package_id'); }
    public function competency() { return $this->belongsTo(Competency::class); }
}
