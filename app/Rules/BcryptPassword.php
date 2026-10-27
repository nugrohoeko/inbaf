<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BcryptPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) return;
        if (strlen($value) > 72) $fail('Kata sandi maksimal 72 byte. Kurangi panjangnya jika menggunakan karakter Unicode.');
        if (str_contains($value, "\0")) $fail('Kata sandi tidak boleh mengandung karakter null.');
    }
}
