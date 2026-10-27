<?php

namespace Tests\Unit;

use App\Services\ScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoringReferenceMatrixTest extends TestCase
{
    #[DataProvider('referenceAnswers')]
    public function test_all_eighteen_question_types_have_known_correct_wrong_and_blank_results(string $type, array $schema, mixed $response, float $expected): void
    {
        $this->assertSame($expected, (new ScoringService)->scoreQuestion(['type' => $type, 'answer_schema' => $schema], $response));
    }

    public static function referenceAnswers(): array
    {
        $rows = [];
        foreach (['single_choice', 'listening', 'reading', 'image', 'video', 'error_identification', 'conversation'] as $type) {
            $rows[$type.' correct'] = [$type, ['correct' => 'b'], 'b', 1.0];
            $rows[$type.' wrong'] = [$type, ['correct' => 'b'], 'a', 0.0];
            $rows[$type.' blank'] = [$type, ['correct' => 'b'], null, 0.0];
        }
        $examples = [
            'multiple_response' => [['correct' => ['a', 'c']], ['c', 'a'], ['a']],
            'true_false' => [['correct' => false], false, true],
            'matching' => [['correct' => ['one' => 'a', 'two' => 'b']], ['two' => 'b', 'one' => 'a'], ['one' => 'b', 'two' => 'a']],
            'drag_drop' => [['correct' => ['one' => 'a', 'two' => 'b']], ['one' => 'a', 'two' => 'b'], ['one' => 'b', 'two' => 'a']],
            'dropdown' => [['correct' => ['gap' => 'because']], ['gap' => 'because'], ['gap' => 'despite']],
            'fill_blank' => [['correct' => ['gap' => ['makan', 'menyantap']]], ['gap' => ' MENYANTAP '], ['gap' => 'minum']],
            'short_answer' => [['accepted' => ['terima kasih']], ' TERIMA   KASIH ', 'selamat tinggal'],
            'sentence_ordering' => [['correct' => ['a', 'b', 'c']], ['a', 'b', 'c'], ['b', 'a', 'c']],
            'word_ordering' => [['correct' => ['saya', 'belajar', 'bahasa']], ['saya', 'belajar', 'bahasa'], ['bahasa', 'saya', 'belajar']],
        ];
        foreach ($examples as $type => [$schema, $correct, $wrong]) {
            $rows[$type.' correct'] = [$type, $schema, $correct, 1.0];
            $rows[$type.' wrong'] = [$type, $schema, $wrong, 0.0];
            $rows[$type.' blank'] = [$type, $schema, [], 0.0];
        }
        foreach (['essay', 'speaking'] as $type) {
            $rows[$type.' requires manual review'] = [$type, ['correct' => 'a'], 'a', 0.0];
            $rows[$type.' no automatic fallback'] = [$type, [], ['media_asset_id' => 123], 0.0];
            $rows[$type.' blank'] = [$type, [], null, 0.0];
        }

        return $rows;
    }

    #[DataProvider('partialAnswers')]
    public function test_partial_scoring_uses_known_fractions_and_never_rewards_extra_choices(string $type, array $schema, mixed $response, float $expected): void
    {
        $this->assertSame($expected, (new ScoringService)->scoreQuestion(['type' => $type, 'answer_schema' => $schema + ['partial_credit' => true]], $response));
    }

    public static function partialAnswers(): array
    {
        $rows = [];
        foreach (['matching', 'drag_drop', 'dropdown', 'fill_blank', 'sentence_ordering', 'word_ordering'] as $type) {
            $rows[$type] = [$type, ['correct' => ['a', 'b', 'c', 'd']], ['a', 'wrong', 'c', 'wrong'], 0.5];
        }
        $rows['duplicate chosen option counted once'] = ['multiple_response', ['correct' => ['a', 'b']], ['a', 'a'], 0.5];
        $rows['incorrect choices reduce score'] = ['multiple_response', ['correct' => ['a', 'b', 'c', 'd']], ['a', 'b', 'c', 'wrong'], 0.5];
        $rows['penalty cannot become negative'] = ['multiple_response', ['correct' => ['a']], ['b', 'c', 'd'], 0.0];

        return $rows;
    }
}
