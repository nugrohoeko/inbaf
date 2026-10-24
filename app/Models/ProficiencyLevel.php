<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProficiencyLevel extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['min_score' => 'float']; }
}
