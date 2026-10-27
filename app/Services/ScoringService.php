<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ExamAttempt;
use App\Models\ProficiencyLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScoringService
{
    public function isManual(array $question): bool
    {
        return in_array($question['type'], ['essay', 'speaking'], true) || (bool) ($question['metadata']['manual'] ?? false);
    }

    /** Normalize both legacy named rubrics and the current weighted criterion list. */
    public function rubric(array $question): array
    {
        $rubric = $question['metadata']['rubric'] ?? [];
        if (! is_array($rubric) || count($rubric) > 50) {
            throw ValidationException::withMessages(['metadata.rubric' => 'Use at most 50 assessment criteria.']);
        }
        $criteria = [];
        foreach ($rubric as $key => $criterion) {
            if (! array_is_list($rubric)) $criterion = ['name' => (string) $key, 'weight' => $criterion];
            elseif (is_string($criterion)) $criterion = ['name' => $criterion, 'weight' => 1];
            $name = is_array($criterion) && is_string($criterion['name'] ?? null) ? trim($criterion['name']) : '';
            $weight = is_array($criterion) ? ($criterion['weight'] ?? 1) : null;
            if ($name === '' || mb_strlen($name) > 100 || isset($criteria[$name])
                || ! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0 || (float) $weight > 1000) {
                throw ValidationException::withMessages(['metadata.rubric' => 'Each criterion needs a unique name and a positive weight up to 1000.']);
            }
            $criteria[$name] = (float) $weight;
        }

        return $criteria;
    }

    /** The captured rubric, rather than a client-supplied total, determines the grade. */
    public function manualScore(array $question, array $scores, float $overall): float
    {
        $rubric = $this->rubric($question);
        if (! $rubric) return round($overall, 2);
        if (array_diff(array_keys($scores), array_keys($rubric))) {
            throw ValidationException::withMessages(['scores' => 'Only the captured assessment criteria may be graded.']);
        }
        $total = 0;
        foreach ($rubric as $name => $weight) {
            if (! array_key_exists($name, $scores) || ! is_numeric($scores[$name]) || ! is_finite((float) $scores[$name])
                || (float) $scores[$name] < 0 || (float) $scores[$name] > 100) {
                throw ValidationException::withMessages(['scores.'.$name => 'Grade every assessment criterion from 0 to 100.']);
            }
            $total += (float) $scores[$name] * $weight;
        }

        return round($total / array_sum($rubric), 2);
    }

    /** Return a fraction between zero and one, using the attempt's immutable key. */
    public function scoreQuestion(array $question, mixed $response): float
    {
        $this->validateAnswerShape($question);
        if ($response === null || $response === '' || $response === []) return 0;
        if (is_array($response) && array_keys($response) === ['answer']) $response = $response['answer'];
        $schema = $question['answer_schema'] ?? [];
        $correct = $schema['correct'] ?? null;
        $type = $question['type'];
        if ($this->isManual($question)) return 0;
        $partial = (bool) ($schema['partial_credit'] ?? $question['metadata']['partial_credit'] ?? false);
        if ($type === 'multiple_response') {
            if (! is_array($response) || ! is_array($correct)) return 0;
            if (count(array_filter([...$response, ...$correct], fn ($v) => !is_string($v) && !is_int($v)))) return 0;
            $chosen = array_values(array_unique(array_map('strval', $response)));
            $expected = array_values(array_unique(array_map('strval', $correct)));
            if (! $expected) return 0;
            sort($chosen); sort($expected);
            if (! $partial) return $chosen === $expected ? 1 : 0;
            return max(0, (count(array_intersect($chosen, $expected)) - count(array_diff($chosen, $expected))) / count($expected));
        }
        if (in_array($type, ['matching', 'drag_drop', 'dropdown', 'fill_blank', 'sentence_ordering', 'word_ordering'], true) && is_array($correct)) {
            if (! is_array($response) || ! count($correct)) return 0;
            if (array_diff(array_keys($response), array_keys($correct))) return 0;
            $matches = 0;
            foreach ($correct as $key => $value) {
                $given = $response[$key] ?? null;
                if ($type === 'fill_blank') {
                    $matches += is_scalar($given) && (is_array($value)
                        ? in_array($this->normalize($given), array_map(fn ($v) => $this->normalize($v), $value), true)
                        : $this->normalize($given) === $this->normalize($value)) ? 1 : 0;
                } else {
                    $matches += (is_string($given) || is_int($given)) && (is_string($value) || is_int($value)) && (string)$given === (string)$value ? 1 : 0;
                }
            }
            if (count($response) !== count($correct) && ! $partial) return 0;
            return $partial ? $matches / count($correct) : ($matches === count($correct) ? 1 : 0);
        }
        if ($type === 'short_answer' || $type === 'fill_blank') {
            if (!is_scalar($response)) return 0;
            $accepted = $schema['accepted'] ?? [];
            if ($correct !== null) $accepted = array_merge($accepted, is_array($correct) ? $correct : [$correct]);
            return in_array($this->normalize($response), array_map(fn ($v) => $this->normalize($v), $accepted), true) ? 1 : 0;
        }
        if ($correct === null || !is_scalar($response)) return 0;
        if ($type === 'true_false') return $this->normalize($response) === $this->normalize($correct) ? 1 : 0;
        return (is_string($response) || is_int($response)) && (is_string($correct) || is_int($correct)) && (string)$response === (string)$correct ? 1 : 0;
    }

    /** Reject malformed stored/imported configuration before typed array operations. */
    public function validateAnswerShape(array $question): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $schema = $question['answer_schema'] ?? [];
        $metadata = $question['metadata'] ?? [];
        if (! is_array($schema)) $fail('answer_schema', 'The answer configuration must be an object or array.');
        if (! is_array($metadata)) $fail('metadata', 'Question metadata must be an object or array.');
        $flat = fn (array $values) => count(array_filter($values, fn ($value) => ! is_scalar($value))) === 0;
        if (isset($schema['accepted']) && (! is_array($schema['accepted']) || ! array_is_list($schema['accepted']) || ! $flat($schema['accepted']))) {
            $fail('answer_schema.accepted', 'Accepted answers must be a list of text or scalar values.');
        }
        foreach (['left_items', 'right_items', 'matches', 'blanks'] as $field) {
            if (! isset($metadata[$field])) continue;
            if (! is_array($metadata[$field]) || ! array_is_list($metadata[$field])) $fail('metadata.'.$field, 'Use a list of question item objects.');
            foreach ($metadata[$field] as $index => $item) {
                if (! is_array($item)) $fail('metadata.'.$field.'.'.$index, 'Each question item must be an object.');
                if (isset($item['id']) && ! is_string($item['id']) && ! is_int($item['id'])) $fail('metadata.'.$field.'.'.$index.'.id', 'Item identifiers must be text or integers.');
                if ($field !== 'blanks' || ! isset($item['options'])) continue;
                if (! is_array($item['options']) || ! array_is_list($item['options'])) $fail('metadata.blanks.'.$index.'.options', 'Blank options must be a list of option objects.');
                foreach ($item['options'] as $optionIndex => $option) {
                    if (! is_array($option) || (isset($option['id']) && ! is_string($option['id']) && ! is_int($option['id']))) {
                        $fail('metadata.blanks.'.$index.'.options.'.$optionIndex, 'Each blank option must be an object with a scalar identifier.');
                    }
                }
            }
        }
        if ($this->isManual($question)) return;
        $correct = $schema['correct'] ?? null;
        if ($correct === null || $correct === '' || $correct === []) return; // Completeness is checked when publishing.
        $type = $question['type'] ?? '';
        if ($type === 'fill_blank') {
            if (! is_array($correct)) {
                if (! is_scalar($correct)) $fail('answer_schema.correct', 'A blank answer must be scalar or an array of scalar answers and aliases.');
                return;
            }
            foreach ($correct as $answer) {
                if (is_array($answer) ? ! $flat($answer) : ! is_scalar($answer)) $fail('answer_schema.correct', 'Blank answers and aliases must contain scalar values only.');
            }
            return;
        }
        if (in_array($type, ['multiple_response', 'matching', 'drag_drop', 'sentence_ordering', 'word_ordering'], true)) {
            if (! is_array($correct) || ! $flat($correct)) $fail('answer_schema.correct', 'The answer key must be an array of scalar option identifiers.');
            return;
        }
        if (in_array($type, ['short_answer', 'dropdown'], true)) {
            if (is_array($correct) ? ! $flat($correct) : ! is_scalar($correct)) $fail('answer_schema.correct', 'The answer key must be scalar or a flat array of scalar answers.');
            return;
        }
        if (! is_scalar($correct)) $fail('answer_schema.correct', 'The answer key must contain one scalar answer.');
    }

    private function normalize(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (! is_scalar($value) && $value !== null) return '__invalid__';
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)), 'UTF-8');
    }

    public function finalize(ExamAttempt $attempt): ExamAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $attempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            // A retry must never rewrite an official result or diverge from its signed certificate.
            if ($attempt->status === 'completed') return $attempt->load(['package', 'certificate']);
            if ($attempt->status === 'in_progress' && ! $attempt->submitted_at) {
                throw ValidationException::withMessages(['attempt' => 'Kirim ujian sebelum melakukan penilaian.']);
            }
            $snapshot = $attempt->snapshot;
            $answers = $attempt->answers()->get()->keyBy('question_key');
            $sections = []; $details = []; $pending = 0; $weighted = 0; $totalWeight = 0; $totalEarned = 0; $totalMaximum = 0;
            $testType = $snapshot['test_type'] ?? $snapshot['package']['test_type'] ?? $attempt->test_type ?? 'certification';
            $method = $snapshot['scoring']['method'] ?? 'weighted_competency';
            foreach ($snapshot['sections'] ?? [] as $section) {
                $earned = 0; $maximum = 0; $answered = 0; $sectionPending = 0;
                foreach ($section['questions'] as $question) {
                    $answer = $answers->get($question['key']);
                    $response = $answer?->response;
                    $hasResponse = $response !== null && $response !== '' && $response !== [];
                    $weight = max(0, (float) ($question['weight'] ?? 1));
                    $manual = $this->isManual($question);
                    if ($manual && ! $hasResponse && $answer && $answer->manual_score === null) {
                        $answer->update(['manual_score' => 0, 'feedback' => 'Tidak ada jawaban yang dikirim.']);
                    }
                    $waiting = $manual && $hasResponse && $answer?->manual_score === null;
                    if ($waiting) { $pending++; $sectionPending++; }
                    $fraction = $manual ? max(0, min(1, (float) ($answer?->manual_score ?? 0) / 100)) : $this->scoreQuestion($question, $response);
                    $earned += $fraction * $weight; $maximum += $weight; $answered += (int) $hasResponse;
                    $details[] = ['key' => $question['key'], 'question_key' => $question['key'], 'question_id' => $question['id'], 'type' => $question['type'], 'competency_id' => $section['competency_id'], 'score' => $waiting ? null : round($fraction * 100, 2), 'weight' => $weight, 'manual' => $manual, 'pending' => $waiting, 'feedback' => $answer?->feedback];
                }
                $score = $maximum > 0 ? $earned / $maximum * 100 : 0;
                $totalEarned += $earned; $totalMaximum += $maximum;
                $sectionWeight = max(0, (float) ($section['weight'] ?? 1));
                $weighted += $score * $sectionWeight; $totalWeight += $sectionWeight;
                $sections[] = ['section_id' => $section['id'], 'competency_id' => $section['competency_id'], 'code' => $section['competency']['code'] ?? '', 'name' => $section['competency']['name'] ?? $section['title'], 'name_en' => $section['competency']['name_en'] ?? $section['title'], 'score' => $score, 'weight' => $sectionWeight, 'earned_points' => $earned, 'maximum_points' => $maximum, 'question_count' => count($section['questions']), 'answered' => $answered, 'pending' => $sectionPending];
            }
            $score = $totalWeight > 0 ? $weighted / $totalWeight : 0;
            if ($method === 'weighted_questions') $score = $totalMaximum > 0 ? $totalEarned / $totalMaximum * 100 : 0;
            if ($method === 'equal_competency') {
                $scores = collect($sections)->groupBy('competency_id')->map(function ($rows) {
                    $weight = $rows->sum('weight');
                    return $weight > 0 ? $rows->sum(fn ($row) => $row['score'] * $row['weight']) / $weight : 0;
                });
                $score = (float) $scores->avg();
            }
            $preciseScore = $score;
            $score = round($score, 2);
            $levels = collect($snapshot['levels'] ?? ProficiencyLevel::orderBy('min_score')->get()->toArray());
            $level = $levels->sortByDesc('min_score')->first(fn ($level) => $score >= (float) $level['min_score']);
            $passScore = (float) ($snapshot['package']['pass_score'] ?? 60);
            $passed = $pending === 0 && $score >= $passScore;
            $scaleMax = max(1, (float) ($snapshot['scoring']['scale_max'] ?? 100));
            $displayScore = round($preciseScore / 100 * $scaleMax, 2);
            $competencies = collect($sections)->groupBy('competency_id');
            $sections = array_map(function ($section) use ($scaleMax) { $section['normalized_score'] = round($section['score'], 2); $section['score'] = round($section['score'] / 100 * $scaleMax, 2); return $section; }, $sections);
            $result = ['test_type' => $testType, 'scoring_method' => $method, 'total_score' => $pending ? null : $displayScore, 'score' => $pending ? null : $displayScore, 'normalized_score' => $pending ? null : $score, 'provisional_score' => $pending ? $displayScore : null, 'level' => $pending ? null : ($level['code'] ?? null), 'level_name' => $pending ? null : ($level['name'] ?? null), 'level_recommendation' => $pending ? null : ($level['recommendation'] ?? null), 'pass_score' => round($passScore / 100 * $scaleMax, 2), 'pass_percentage' => $passScore, 'passed' => $passed, 'manual_pending' => $pending, 'competency_scores' => $sections, 'sections' => $sections, 'question_scores' => $details, 'graded_at' => now()->toISOString(), 'score_max' => $scaleMax, 'scale' => '0–'.$scaleMax, 'standard' => 'INBAF internal proficiency scale'];
            $result['competency_scores'] = $competencies->map(function ($rows) use ($totalWeight, $totalMaximum, $method, $competencies, $scaleMax) {
                $sectionWeight = $rows->sum('weight');
                $normalized = $sectionWeight > 0 ? $rows->sum(fn ($row) => $row['score'] * $row['weight']) / $sectionWeight : 0;
                $weight = $sectionWeight; $denominator = $totalWeight;
                if ($method === 'weighted_questions') {
                    $weight = $rows->sum('maximum_points'); $denominator = $totalMaximum;
                    $normalized = $weight > 0 ? $rows->sum('earned_points') / $weight * 100 : 0;
                } elseif ($method === 'equal_competency') {
                    $weight = 1; $denominator = $competencies->count();
                }
                return ['competency_id' => $rows->first()['competency_id'], 'code' => $rows->first()['code'], 'name' => $rows->first()['name'], 'name_en' => $rows->first()['name_en'], 'score' => round($normalized / 100 * $scaleMax, 2), 'normalized_score' => round($normalized, 2), 'weight' => $weight, 'weight_percentage' => $denominator > 0 ? round($weight / $denominator * 100, 2) : 0, 'question_count' => $rows->sum('question_count'), 'answered' => $rows->sum('answered'), 'pending' => $rows->sum('pending')];
            })->values()->all();
            $threshold = (float) ($snapshot['scoring']['recommendation_threshold'] ?? 60);
            $result['recommendations'] = $pending ? [] : collect($result['competency_scores'])->filter(fn ($row) => $row['normalized_score'] < $threshold)->map(fn ($row) => ['competency_id' => $row['competency_id'], 'code' => $row['code'], 'name' => $row['name'], 'name_en' => $row['name_en'], 'normalized_score' => $row['normalized_score'], 'recommendation' => 'Pelajari kembali materi '.$row['name'].' dan kerjakan latihan bertahap sesuai level '.($level['code'] ?? 'awal').'.', 'recommendation_en' => 'Review '.$row['name_en'].' materials and practise progressively at level '.($level['code'] ?? 'beginner').'.'])->values()->all();
            $result['certificate_eligible'] = $testType === 'certification' && ! $pending && $passed && ($snapshot['certificate_policy']['enabled'] ?? $snapshot['package']['certificate_enabled'] ?? false);
            $attempt->update(['status' => $pending ? 'pending_review' : 'completed', 'score' => $pending ? null : $displayScore, 'level' => $pending ? null : ($level['code'] ?? null), 'result' => $result]);
            if ($result['certificate_eligible'] && class_exists(CertificateService::class)) {
                app(CertificateService::class)->issue($attempt);
            }
            AuditLog::create(['user_id' => $attempt->user_id, 'event' => $pending ? 'attempt.awaiting_review' : 'attempt.graded', 'subject_type' => 'exam_attempt', 'subject_id' => $attempt->id, 'data' => ['score' => $attempt->score, 'manual_pending' => $pending]]);
            return $attempt->fresh(['package', 'certificate']);
        }, 5);
    }
}
