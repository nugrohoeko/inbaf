<?php

namespace App\Jobs;

use App\Models\ExamAttempt;
use App\Services\AttemptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeExpiredAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public string $attemptId) {}

    public function handle(AttemptService $service): void
    {
        $attempt = ExamAttempt::find($this->attemptId);
        if ($attempt) $service->expire($attempt);
    }
}
