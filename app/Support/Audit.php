<?php

namespace App\Support;

use App\Models\AuditLog;

class Audit
{
    public static function record(string $event, $model = null, array $data = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(), 'event' => $event,
            'subject_type' => $model ? class_basename($model) : null,
            'subject_id' => $model ? (string) $model->getKey() : null,
            'data' => $data, 'ip' => request()?->ip(),
        ]);
    }
}
