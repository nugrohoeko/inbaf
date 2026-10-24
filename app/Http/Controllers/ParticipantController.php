<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Certificate, Competency, ExamAttempt, ExamPackage, Question, TestType, User};
use App\Services\AttemptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ParticipantController extends Controller
{
    public static function availablePackages()
    {
        return ExamPackage::where('published', true)->whereIn('test_type', TestType::where('active', true)->select('code'))->with('sections.competency');
    }

    public static function packageData(ExamPackage $package): array
    {
        $package->loadMissing('sections.competency');
        $data = $package->toArray();
        $data['requires_access_code'] = ! empty($data['settings']['access_code']);
        unset($data['settings']['access_code']);
        $data['effective_policy'] = $package->effectivePolicy();
        $data['policy'] = $data['effective_policy'];
        $data['timer_enabled'] = $data['policy']['timer_enabled'];
        $data['attempts_limit'] = $data['policy']['attempts_limit'];
        $data['certificate_enabled'] = $package->test_type === 'certification' && $package->certificate_enabled;
        $data['questions_count'] = $package->sections->sum('question_count');
        $data['question_count'] = $data['questions_count'];
        $data['competencies_count'] = $package->sections->pluck('competency_id')->unique()->count();
        $data['competencies'] = $package->sections->pluck('competency')->unique('id')->values();
        $data['sections'] = $package->sections->map(function ($section) { $data = $section->toArray(); unset($data['question_ids']); return $data; })->values();
        return $data;
    }

    public function packages(Request $request)
    {
        $filters = $request->validate(['test_type' => ['nullable', Rule::in(['practice', 'placement', 'certification'])]]);
        $query = self::availablePackages();
        if (!empty($filters['test_type'])) $query->where('test_type', $filters['test_type']);
        return response()->json(['packages' => $query->get()->map(fn ($package) => self::packageData($package))]);
    }

    public function package(Request $request, ExamPackage $package)
    {
        abort_unless($package->published || $request->user()?->isAdmin(), 404);
        abort_unless($request->user()?->isAdmin() || TestType::where('code', $package->test_type)->where('active', true)->exists(), 404);
        $data = self::packageData($package);
        $data['attempts_used'] = $request->user() ? $package->attempts()->where('test_type', $package->test_type)->where('user_id', $request->user()->id)->count() : 0;
        $data['active_attempt'] = $request->user() ? $package->attempts()->where('test_type', $package->test_type)->where('user_id', $request->user()->id)->where('status', 'in_progress')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first() : null;
        return response()->json(['package' => $data]);
    }

    public function dashboard(Request $request, AttemptService $attempts)
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])], 'package_id' => ['nullable', 'integer', 'exists:exam_packages,id'], 'test_type' => ['nullable', Rule::in(['practice', 'placement', 'certification'])]]);
        $user = $request->user();
        // Expiry is enforced by the server even when the browser has disconnected.
        $expired = ExamAttempt::where('status', 'in_progress')->where('expires_at', '<=', now());
        if (! $user->isAdmin()) $expired->where('user_id', $user->id);
        $expired->limit(100)->get()->each(fn ($attempt) => $attempts->expire($attempt));
        $query = ExamAttempt::query();
        if (! $user->isAdmin()) $query->where('user_id', $user->id);
        if (! empty($filters['date_from'])) $query->where('started_at', '>=', \Illuminate\Support\Carbon::parse($filters['date_from'])->startOfDay());
        if (! empty($filters['date_to'])) $query->where('started_at', '<=', \Illuminate\Support\Carbon::parse($filters['date_to'])->endOfDay());
        if (! empty($filters['package_id'])) $query->where('exam_package_id', $filters['package_id']);
        if (! empty($filters['test_type'])) $query->where('test_type', $filters['test_type']);
        $completed = (clone $query)->where('status', 'completed');
        // Policies may change the presentation scale between attempts, even within one package.
        $scoreTotal = 0; $scoreCount = 0; $bestScore = 0; $monthlyScores = [];
        foreach ((clone $completed)->select(['score', 'result', 'started_at'])->cursor() as $completedAttempt) {
            $normalized = self::normalizedScore($completedAttempt);
            if ($normalized === null) continue;
            $scoreTotal += $normalized; $scoreCount++; $bestScore = max($bestScore, $normalized);
            $month = $completedAttempt->started_at->format('Y-m');
            $monthlyScores[$month] ??= ['total' => 0, 'count' => 0];
            $monthlyScores[$month]['total'] += $normalized;
            $monthlyScores[$month]['count']++;
        }
        $certificateQuery = Certificate::query();
        if (! $user->isAdmin()) $certificateQuery->where('user_id', $user->id);
        if (!empty($filters['test_type']) && $filters['test_type'] !== 'certification') $certificateQuery->whereRaw('1 = 0');
        $stats = ['total_attempts' => (clone $query)->count(), 'completed_attempts' => (clone $completed)->count(), 'in_progress' => (clone $query)->where('status', 'in_progress')->count(), 'pending_reviews' => (clone $query)->where('status', 'pending_review')->count(), 'average_score' => $scoreCount ? round($scoreTotal / $scoreCount, 1) : 0, 'best_score' => round($bestScore, 2), 'aggregate_score_max' => 100, 'certificates' => (clone $certificateQuery)->where('status', 'valid')->count()];
        $stats['pending_review'] = $stats['pending_reviews'];
        $stats['completed'] = $stats['completed_attempts'];
        $latest = (clone $completed)->orderByDesc('submitted_at')->first();
        $stats['current_level'] = $latest?->level;
        $stats['latest_score'] = $latest?->score;
        $stats['score_max'] = $latest?->result['score_max'] ?? 100;
        $stats['latest_score_max'] = $stats['score_max'];
        if ($user->isAdmin()) {
            $stats += ['participants' => User::where('role', 'participant')->count(), 'total_participants' => User::where('role', 'participant')->count(), 'questions' => Question::count(), 'total_questions' => Question::count(), 'active_questions' => Question::where('status', 'active')->count(), 'packages' => ExamPackage::count(), 'active_packages' => ExamPackage::where('published', true)->count(), 'total_certificates' => Certificate::count()];
            $stats['active_participants'] = User::where('role', 'participant')->where('active', true)->whereIn('id', AuditLog::where('created_at', '>=', now()->subDays(30))->select('user_id'))->count();
        }
        $trend = collect(range(5, 0))->map(function ($offset) use ($query, $monthlyScores) {
            $month = now()->subMonths($offset)->startOfMonth();
            $q = (clone $query)->whereBetween('started_at', [$month, $month->copy()->endOfMonth()]);
            $scores = $monthlyScores[$month->format('Y-m')] ?? ['total' => 0, 'count' => 0];
            return ['month' => $month->format('M'), 'date' => $month->format('Y-m'), 'attempts' => $q->count(), 'average_score' => $scores['count'] ? round($scores['total'] / $scores['count'], 1) : 0];
        });
        $recent = (clone $query)->with(['package', 'user:id,name,email', 'certificate'])->latest('started_at')->limit(8)->get()->map(fn ($attempt) => self::attemptData($attempt));
        $scoreRows = (clone $completed)->orderByDesc('submitted_at')->limit(100)->get()->flatMap(fn ($attempt) => $attempt->result['competency_scores'] ?? []);
        $competencyScores = $scoreRows->groupBy('competency_id')->map(fn ($rows) => ['competency_id' => $rows->first()['competency_id'], 'name' => $rows->first()['name'], 'code' => $rows->first()['code'], 'score' => round($rows->avg(fn ($row) => $row['normalized_score'] ?? $row['score']), 1)])->values();
        $levelDistribution = (clone $completed)->selectRaw('level, COUNT(*) as count')->whereNotNull('level')->groupBy('level')->orderBy('level')->get();
        $logs = AuditLog::query();
        if (! $user->isAdmin()) $logs->where('user_id', $user->id);
        $typeStats = collect(['practice', 'placement', 'certification'])->mapWithKeys(fn ($type) => [$type => ['total_attempts' => (clone $query)->where('test_type', $type)->count(), 'completed_attempts' => (clone $completed)->where('test_type', $type)->count()]]);
        return response()->json(self::publicData(['stats' => $stats, 'test_type_stats' => $typeStats, 'recent_attempts' => $recent, 'attempts' => $recent, 'packages' => self::availablePackages()->get()->map(fn ($package) => self::packageData($package)), 'certificates' => $certificateQuery->latest('issued_at')->limit(8)->get(), 'trend' => $trend, 'monthly_stats' => $trend, 'competency_scores' => $competencyScores, 'level_distribution' => $levelDistribution, 'filters' => $filters, 'activities' => $logs->with('user:id,name')->latest()->limit(8)->get()]));
    }

    public function profile(Request $request)
    {
        return app(AccountController::class)->legacyUpdate($request, app(\App\Services\ProfilePhotoService::class));
    }

    public function results(Request $request)
    {
        $query = ExamAttempt::where('user_id', $request->user()->id)->whereIn('status', ['pending_review', 'completed'])->with(['package', 'certificate'])->latest('started_at');
        $filters = $request->validate(['test_type' => ['nullable', Rule::in(['practice', 'placement', 'certification'])]]);
        if (!empty($filters['test_type'])) $query->where('test_type', $filters['test_type']);
        return response()->json(self::publicData(['results' => $query->get()->map(fn ($attempt) => self::attemptData($attempt))]));
    }

    public function result(Request $request, ExamAttempt $attempt, AttemptService $service)
    {
        abort_unless($attempt->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $attempt = $service->expire($attempt);
        abort_if($attempt->status === 'in_progress', 409, 'Ujian masih berlangsung.');
        $previous = $attempt->status === 'completed' ? ExamAttempt::where('user_id', $attempt->user_id)->where('exam_package_id', $attempt->exam_package_id)->where('test_type', $attempt->test_type)->where('status', 'completed')->where('started_at', '<', $attempt->started_at)->latest('started_at')->first() : null;
        return response()->json(self::publicData(['attempt' => self::attemptData($attempt->load(['package', 'certificate'])), 'result' => $attempt->result, 'certificate' => $attempt->certificate, 'review_answers' => $service->review($attempt), 'previous_result' => $previous ? ['attempt_id' => $previous->id, 'submitted_at' => $previous->submitted_at, 'normalized_score' => $previous->result['normalized_score'] ?? null, 'total_score' => $previous->score, 'score_max' => $previous->result['score_max'] ?? 100, 'competency_scores' => $previous->result['competency_scores'] ?? []] : null]));
    }

    public function certificates(Request $request)
    {
        return response()->json(self::publicData(['certificates' => $request->user()->certificates()->with('attempt.package')->latest('issued_at')->get()]));
    }

    public static function publicData(mixed $data): mixed
    {
        if ($data instanceof \Illuminate\Contracts\Support\Arrayable) $data = $data->toArray();
        if (! is_array($data)) return $data;
        unset($data['access_code']);
        foreach ($data as $key => $value) $data[$key] = self::publicData($value);
        return $data;
    }

    private static function normalizedScore(ExamAttempt $attempt): ?float
    {
        $result = $attempt->result ?? [];
        if (is_numeric($result['normalized_score'] ?? null)) return max(0, min(100, (float) $result['normalized_score']));
        if ($attempt->score === null) return null;
        $maximum = is_numeric($result['score_max'] ?? null) && (float) $result['score_max'] > 0 ? (float) $result['score_max'] : 100;

        return max(0, min(100, $attempt->score / $maximum * 100));
    }

    public static function attemptData(ExamAttempt $attempt): array
    {
        $data = $attempt->toArray();
        if (!empty($attempt->snapshot['package'])) {
            $data['package'] = collect($attempt->snapshot['package'])->only(['id', 'code', 'name', 'description', 'test_type', 'duration_minutes', 'certificate_enabled'])->all();
            $data['package']['test_type'] = $attempt->test_type;
        }
        return self::publicData($data);
    }
}
