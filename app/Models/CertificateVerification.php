<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CertificateVerification extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['valid' => 'boolean']; }
    public function certificate() { return $this->belongsTo(Certificate::class); }
}
