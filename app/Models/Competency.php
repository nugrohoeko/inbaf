<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Competency extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['active' => 'boolean', 'sort_order' => 'integer']; }
    public function questions() { return $this->hasMany(Question::class); }
    public function sections() { return $this->hasMany(ExamSection::class); }
}
