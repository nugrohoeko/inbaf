<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ParticipantProfile extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['date_of_birth' => 'date:Y-m-d']; }
    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
}
