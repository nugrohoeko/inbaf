<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class QuestionVersion extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['snapshot' => 'array']; }
    public function question() { return $this->belongsTo(Question::class); }
}
