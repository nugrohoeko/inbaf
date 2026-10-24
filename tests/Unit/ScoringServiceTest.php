<?php

namespace Tests\Unit;

use App\Services\ScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    #[DataProvider('answers')]
    public function test_objective_responses_are_compared_without_losing_structure(string $type, array $schema, mixed $response, float $expected): void
    {
        $service = new ScoringService;
        $this->assertSame($expected, $service->scoreQuestion(['type' => $type, 'answer_schema' => $schema, 'metadata' => []], $response));
    }

    public static function answers(): array
    {
        return [
            'single option' => ['single_choice', ['correct' => 'b'], 'b', 1.0],
            'wrong option' => ['single_choice', ['correct' => 'b'], 'a', 0.0],
            'option IDs are case sensitive' => ['single_choice', ['correct' => 'A'], 'a', 0.0],
            'option IDs are not trimmed' => ['single_choice', ['correct' => 'a'], ' a ', 0.0],
            'true boolean' => ['true_false', ['correct' => true], true, 1.0],
            'false boolean' => ['true_false', ['correct' => false], false, 1.0],
            'false string' => ['true_false', ['correct' => false], 'false', 1.0],
            'empty is never correct' => ['short_answer', ['correct' => ''], null, 0.0],
            'set order ignored' => ['multiple_response', ['correct' => ['a', 'c']], ['c', 'a'], 1.0],
            'set extra penalized' => ['multiple_response', ['correct' => ['a', 'c']], ['a', 'b', 'c'], 0.0],
            'set missing penalized' => ['multiple_response', ['correct' => ['a', 'c']], ['a'], 0.0],
            'set IDs preserve case' => ['multiple_response', ['correct' => ['A', 'c']], ['a', 'c'], 0.0],
            'nested set rejected' => ['multiple_response', ['correct' => ['a']], [['a']], 0.0],
            'partial set' => ['multiple_response', ['correct' => ['a', 'c'], 'partial_credit' => true], ['a'], 0.5],
            'partial set wrong option penalty' => ['multiple_response', ['correct' => ['a', 'c'], 'partial_credit' => true], ['a', 'b'], 0.0],
            'matching keys independent of order' => ['matching', ['correct' => ['x' => 'a', 'y' => 'b']], ['y' => 'b', 'x' => 'a'], 1.0],
            'matching missing' => ['matching', ['correct' => ['x' => 'a', 'y' => 'b']], ['x' => 'a'], 0.0],
            'matching extra' => ['matching', ['correct' => ['x' => 'a']], ['x' => 'a', 'z' => 'b'], 0.0],
            'matching IDs preserve case' => ['matching', ['correct' => ['x' => 'A']], ['x' => 'a'], 0.0],
            'matching partial extra' => ['matching', ['correct' => ['x' => 'a'], 'partial_credit' => true], ['x' => 'a', 'z' => 'b'], 0.0],
            'partial matching' => ['matching', ['correct' => ['x' => 'a', 'y' => 'b'], 'partial_credit' => true], ['x' => 'a'], 0.5],
            'ordering correct' => ['word_ordering', ['correct' => ['a', 'b', 'c']], ['a', 'b', 'c'], 1.0],
            'ordering wrong' => ['sentence_ordering', ['correct' => ['a', 'b', 'c']], ['b', 'a', 'c'], 0.0],
            'ordering extra' => ['word_ordering', ['correct' => ['a', 'b']], ['a', 'b', 'c'], 0.0],
            'ordering missing' => ['word_ordering', ['correct' => ['a', 'b']], ['a'], 0.0],
            'short normalized' => ['short_answer', ['accepted' => ['terima kasih']], '  TERIMA   KASIH ', 1.0],
            'nested short response rejected' => ['short_answer', ['accepted' => ['__invalid__']], ['nested' => 'value'], 0.0],
            'fill positional accepted' => ['fill_blank', ['correct' => [['makan', 'menyantap'], 'nasi']], ['menyantap', 'nasi'], 1.0],
            'dropdown mapping' => ['dropdown', ['correct' => ['1' => 'karena']], ['1' => 'karena'], 1.0],
            'nested invalid input' => ['single_choice', ['correct' => 'a'], ['a'], 0.0],
            'manual never automatic' => ['essay', ['correct' => 'a'], 'a', 0.0],
        ];
    }

    public function test_manual_short_answer_is_not_scored_automatically(): void
    {
        $question = ['type' => 'short_answer', 'metadata' => ['manual' => true], 'answer_schema' => ['correct' => 'betul']];
        $this->assertTrue((new ScoringService)->isManual($question));
        $this->assertSame(0.0, (new ScoringService)->scoreQuestion($question, 'betul'));
    }
}
