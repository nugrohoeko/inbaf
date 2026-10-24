<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
class MediaAsset extends Model
{
    protected $guarded = [];
    protected $appends = ['url'];
    protected function casts(): array { return ['metadata' => 'array', 'size' => 'integer']; }
    public function getUrlAttribute(): string { return url('/api/media/'.$this->id); }
    public function groups() { return $this->hasMany(QuestionGroup::class); }
}
