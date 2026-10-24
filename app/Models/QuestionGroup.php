<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class QuestionGroup extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function mediaAsset() { return $this->belongsTo(MediaAsset::class); }
    public function questions() { return $this->hasMany(Question::class); }
}
