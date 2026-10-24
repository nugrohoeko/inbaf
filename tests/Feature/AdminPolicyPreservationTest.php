<?php

namespace Tests\Feature;

use App\Models\{Competency, ExamPackage, ExamSection, SystemSetting, TestType, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPolicyPreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_read_effective_legacy_package_policy_without_changing_it(): void
    {
        $package = $this->legacyPackage();
        $url = '/api/admin/packages/'.$package->id;
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->getJson($url)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]))->getJson($url)
            ->assertOk()->assertJsonPath('policy_overrides', null)
            ->assertJsonPath('effective_policy.score_scale', 700)
            ->assertJsonPath('effective_policy.attempts_limit', 2)
            ->assertJsonPath('effective_policy.show_review', true);

        $this->assertNull($package->fresh()->policy_overrides);
        $this->assertSame(100.0, TestType::where('code', 'certification')->firstOrFail()->score_scale);
    }

    public function test_saving_explicit_legacy_policy_preserves_its_score_scale_and_other_type_defaults(): void
    {
        $package = $this->legacyPackage();
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $url = '/api/admin/packages/'.$package->id;
        $payload = $this->getJson($url)->assertOk()->json();
        $payload['name'] = 'Renamed legacy certification';
        $payload['policy_overrides'] = array_intersect_key($payload['effective_policy'], array_flip([
            'score_scale', 'attempts_limit', 'lock_sections', 'show_review', 'randomize_options',
        ]));

        $this->putJson($url, $payload)->assertOk()->assertJsonPath('name', 'Renamed legacy certification')
            ->assertJsonPath('policy_overrides.score_scale', 700);
        $saved = $package->fresh();
        $this->assertSame(700.0, $saved->testType->effectiveScoring($saved)['scale_max']);
        $this->assertSame(2, $saved->effectivePolicy()['attempts_limit']);
        $this->assertTrue($saved->effectivePolicy()['show_review']);
        $this->assertSame(100.0, TestType::where('code', 'certification')->firstOrFail()->score_scale);
        $this->assertSame(100.0, TestType::where('code', 'practice')->firstOrFail()->score_scale);
    }

    private function legacyPackage(): ExamPackage
    {
        SystemSetting::updateOrCreate(['key' => 'score_scale'], ['value' => 700]);
        TestType::where('code', 'certification')->update(['score_scale' => 100]);
        $competency = Competency::create(['code' => 'legacy-reading', 'name' => 'Membaca', 'name_en' => 'Reading', 'active' => true]);
        $package = ExamPackage::create([
            'code' => 'LEGACY-POLICY', 'name' => 'Legacy certification', 'test_type' => 'certification',
            'policy_overrides' => null, 'published' => false, 'duration_minutes' => 10,
            'attempts_limit' => 2, 'pass_score' => 60, 'certificate_enabled' => false,
            'question_selection' => 'random', 'settings' => ['lock_sections' => false, 'show_review' => true, 'randomize_options' => false],
        ]);
        ExamSection::create([
            'exam_package_id' => $package->id, 'competency_id' => $competency->id,
            'title' => 'Reading', 'position' => 0, 'duration_minutes' => 10, 'weight' => 1,
            'question_count' => 1, 'question_ids' => [], 'randomize' => false,
        ]);

        return $package;
    }
}
