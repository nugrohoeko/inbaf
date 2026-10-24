<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\FinalizeExpiredAttempt;
use App\Models\ExamAttempt;

Artisan::command('inbaf:expire-attempts', function () {
    $count = 0;
    ExamAttempt::where('status', 'in_progress')->where('expires_at', '<=', now())->chunkById(100, function ($attempts) use (&$count) {
        foreach ($attempts as $attempt) {
            FinalizeExpiredAttempt::dispatch($attempt->id);
            $count++;
        }
    });
    $this->info("Queued {$count} expired attempts for submission.");
})->purpose('Submit expired attempts through the queue, including disconnected participants.');

Schedule::command('inbaf:expire-attempts')->everyMinute()->withoutOverlapping();
