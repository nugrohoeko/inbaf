<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Certificate extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['payload' => 'array', 'expires_at' => 'datetime', 'issued_at' => 'datetime']; }
    public function attempt() { return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id'); }
    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
    public function verifications() { return $this->hasMany(CertificateVerification::class); }
}
