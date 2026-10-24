<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SystemSetting extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['value' => 'json']; }
    public static function getValue(string $key, mixed $default = null): mixed { return static::where('key', $key)->first()?->value ?? $default; }
}
