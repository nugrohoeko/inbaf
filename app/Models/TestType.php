<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestType extends Model
{
    public const CODES = ['practice', 'placement', 'certification'];

    public const POLICY_FIELDS = ['timer_enabled', 'security_monitoring', 'require_fullscreen', 'tab_switch_limit', 'lock_sections', 'attempts_limit', 'show_review', 'randomize_options', 'score_scale', 'scoring_method', 'level_thresholds', 'recommendation_threshold'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'timer_enabled' => 'boolean', 'security_monitoring' => 'boolean', 'require_fullscreen' => 'boolean', 'lock_sections' => 'boolean', 'show_review' => 'boolean', 'randomize_options' => 'boolean', 'tab_switch_limit' => 'integer', 'attempts_limit' => 'integer', 'score_scale' => 'float', 'recommendation_threshold' => 'float', 'level_thresholds' => 'array'];
    }

    public function packages() { return $this->hasMany(ExamPackage::class, 'test_type', 'code'); }

    public function publicData(): array { return $this->toArray(); }

    public function effectivePolicy(?ExamPackage $package = null): array
    {
        $policy = collect($this->toArray())->only(self::POLICY_FIELDS)->all();
        // Existing certification packages retain their explicitly configured legacy rules.
        if ($package && $package->test_type === 'certification' && $package->policy_overrides === null) {
            $policy['attempts_limit'] = $package->attempts_limit;
            $policy['score_scale'] = (float) SystemSetting::getValue('score_scale', $policy['score_scale'] ?? 100);
            foreach (['lock_sections', 'show_review', 'randomize_options'] as $key) {
                if (array_key_exists($key, $package->settings ?? [])) $policy[$key] = $package->settings[$key];
            }
        }
        foreach ($package?->policy_overrides ?? [] as $key => $value) {
            if ($value !== null && in_array($key, self::POLICY_FIELDS, true)) $policy[$key] = $value;
        }
        if (! ($policy['timer_enabled'] ?? true)) $policy['lock_sections'] = false;

        return $policy;
    }

    public function effectiveScoring(?ExamPackage $package = null): array
    {
        $policy = $this->effectivePolicy($package);

        return ['scale_max' => (float) ($policy['score_scale'] ?? 100), 'method' => $policy['scoring_method'] ?? 'weighted_competency', 'recommendation_threshold' => (float) ($policy['recommendation_threshold'] ?? 60)];
    }

    public function effectiveLevels(?ExamPackage $package = null): array
    {
        return $this->effectivePolicy($package)['level_thresholds'] ?? [];
    }
}
