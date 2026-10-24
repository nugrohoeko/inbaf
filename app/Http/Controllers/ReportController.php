<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Support\Audit;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function export(Request $request)
    {
        $filters = $request->validate(['date_from' => 'nullable|date', 'date_to' => 'nullable|date'.($request->filled('date_from') ? '|after_or_equal:date_from' : ''), 'package_id' => 'nullable|integer|exists:exam_packages,id', 'test_type' => 'nullable|in:practice,placement,certification']);
        $query = ExamAttempt::with(['user:id,name,email', 'package:id,name']);
        if (!empty($filters['date_from'])) $query->whereDate('started_at', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('started_at', '<=', $filters['date_to']);
        if (!empty($filters['package_id'])) $query->where('exam_package_id', $filters['package_id']);
        if (!empty($filters['test_type'])) $query->where('test_type', $filters['test_type']);
        Audit::record('reports.exported', null, $filters);
        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'w'); fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Attempt ID', 'Participant', 'Email', 'Assessment', 'Started (UTC)', 'Submitted (UTC)', 'Status', 'Final score', 'Score maximum', 'Normalized score (%)', 'Internal level', 'Test type'], ',', '"', '');
            foreach ($query->lazyById(500) as $attempt) {
                $values = [$attempt->id, $attempt->snapshot['participant_name'] ?? $attempt->user->name, $attempt->user->email, $attempt->snapshot['package']['name'] ?? $attempt->package->name, $attempt->started_at->utc()->toIso8601String(), $attempt->submitted_at?->utc()->toIso8601String(), $attempt->status, $attempt->status === 'completed' ? $attempt->score : null, $attempt->result['score_max'] ?? 100, $attempt->status === 'completed' ? ($attempt->result['normalized_score'] ?? $attempt->score) : null, $attempt->status === 'completed' ? $attempt->level : null];
                $values[] = $attempt->test_type;
                $values = array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, $values);
                fputcsv($file, $values, ',', '"', '');
            }
            fclose($file);
        }, 'inbaf-results-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
