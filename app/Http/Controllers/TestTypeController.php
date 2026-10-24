<?php

namespace App\Http\Controllers;

use App\Models\TestType;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TestTypeController extends Controller
{
    public function index()
    {
        return response()->json(['test_types' => TestType::orderBy('id')->get()]);
    }

    public static function policyRules(string $prefix = '', bool $nullable = false): array
    {
        $optional = $nullable ? 'sometimes|nullable|' : 'sometimes|';
        $rules = [];
        foreach (['timer_enabled', 'lock_sections', 'show_review', 'security_monitoring', 'require_fullscreen', 'randomize_options'] as $field) {
            $rules[$prefix.$field] = $optional.'boolean';
        }
        foreach (['attempts_limit' => 'integer|min:0|max:10000', 'tab_switch_limit' => 'integer|min:0|max:1000', 'score_scale' => 'numeric|min:1|max:10000', 'recommendation_threshold' => 'numeric|min:0|max:100'] as $field => $rule) {
            $rules[$prefix.$field] = $optional.$rule;
        }
        $rules[$prefix.'scoring_method'] = [$nullable ? 'nullable' : 'sometimes', Rule::in(['weighted_competency', 'weighted_questions', 'equal_competency'])];
        $rules[$prefix.'level_thresholds'] = $optional.'array|min:1|max:30';
        $rules[$prefix.'level_thresholds.*.code'] = 'required|string|max:20|distinct';
        $rules[$prefix.'level_thresholds.*.name'] = 'required|string|max:120';
        $rules[$prefix.'level_thresholds.*.min_score'] = 'required|numeric|min:0|max:100|distinct';
        $rules[$prefix.'level_thresholds.*.recommendation'] = 'nullable|string|max:2000';

        return $rules;
    }

    public static function validateThresholds(?array $levels, string $field = 'level_thresholds'): void
    {
        if ($levels !== null && !collect($levels)->contains(fn ($level) => (float) $level['min_score'] === 0.0)) {
            throw ValidationException::withMessages([$field => 'Sertakan satu tingkat dengan batas minimum 0 agar seluruh skor dapat diklasifikasikan.']);
        }
    }

    public function update(Request $request, string $code)
    {
        $type = TestType::where('code', $code)->firstOrFail();
        $data = $request->validate(array_merge([
            'name' => 'sometimes|required|string|max:120', 'name_en' => 'sometimes|required|string|max:120',
            'description' => 'nullable|string|max:5000', 'description_en' => 'nullable|string|max:5000',
            'active' => 'sometimes|boolean',
        ], self::policyRules()));
        self::validateThresholds($data['level_thresholds'] ?? null);
        DB::transaction(function () use ($type, $data) {
            $type->update($data);
            Audit::record('test_types.updated', $type, ['fields' => array_keys($data)]);
        });

        return response()->json(['test_type' => $type->fresh(), 'test_types' => TestType::orderBy('id')->get()]);
    }
}
