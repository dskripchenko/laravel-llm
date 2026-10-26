<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Tests\Unit;

use Dskripchenko\Llm\Schema\JsonOutput;
use Dskripchenko\Llm\Schema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'type' => ['type' => 'string', 'enum' => ['bug', 'feature']],
            'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 10],
            'score' => ['type' => 'number'],
            'labels' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 5], 'maxItems' => 2],
            'assignee' => ['type' => ['string', 'null']],
            'meta' => [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean']],
                'required' => ['ok'],
            ],
        ],
        'required' => ['type', 'priority'],
        'additionalProperties' => false,
    ];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'valid' => ['{"type":"bug","priority":2,"title":"Crash","score":0.5,"labels":["ui"],"assignee":null,"meta":{"ok":true}}', []];
        yield 'integer as 2.0 is an integer' => ['{"type":"bug","priority":2.0}', []];
        yield 'missing required' => ['{"type":"bug"}', ['$: missing required property `priority`']];
        yield 'enum' => ['{"type":"chore","priority":1}', ['$.type: must be one of "bug", "feature", got "chore"']];
        yield 'type' => ['{"type":"bug","priority":"high"}', ['$.priority: expected integer, got string']];
        yield 'minimum' => ['{"type":"bug","priority":0}', ['$.priority: must be >= 1']];
        yield 'maximum' => ['{"type":"bug","priority":9}', ['$.priority: must be <= 4']];
        yield 'minLength' => ['{"type":"bug","priority":1,"title":"ab"}', ['$.title: must be at least 3 characters long']];
        yield 'maxLength counts characters' => ['{"type":"bug","priority":1,"title":"длинноеимяя"}', ['$.title: must be at most 10 characters long']];
        yield 'items' => ['{"type":"bug","priority":1,"labels":["ui",7]}', ['$.labels[1]: expected string, got integer']];
        yield 'maxItems' => ['{"type":"bug","priority":1,"labels":["a","b","c"]}', ['$.labels: must have at most 2 item(s)']];
        yield 'nullable union' => ['{"type":"bug","priority":1,"assignee":5}', ['$.assignee: expected string or null, got integer']];
        yield 'nested required' => ['{"type":"bug","priority":1,"meta":{}}', ['$.meta: missing required property `ok`']];
        yield 'empty object is not an array' => ['{"type":"bug","priority":1,"labels":{}}', ['$.labels: expected array, got object']];
        yield 'additional property' => ['{"type":"bug","priority":1,"extra":1}', ['$: unexpected property `extra`']];
        yield 'root type' => ['[1,2]', ['$: expected object, got array']];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('cases')]
    public function test_validates_the_subset(string $json, array $expected): void
    {
        $this->assertSame($expected, (new Validator)->validate(json_decode($json), self::SCHEMA));
    }

    public function test_const_and_bounds_on_floats(): void
    {
        $v = new Validator;
        $this->assertSame([], $v->validate(1.5, ['type' => 'number', 'minimum' => 1, 'maximum' => 2]));
        $this->assertSame(['$: must be "x"'], $v->validate('y', ['const' => 'x']));
    }

    public function test_extracts_json_from_fences_think_blocks_and_prose(): void
    {
        $this->assertEquals((object) ['a' => 1], JsonOutput::parse("```json\n{\"a\":1}\n```")['value']);
        $this->assertEquals((object) ['a' => 1], JsonOutput::parse("<think>hmm {no}</think>\n{\"a\":1}")['value']);
        $this->assertEquals((object) ['a' => 1], JsonOutput::parse('Sure! Here it is: {"a":1} Hope it helps.')['value']);
        $this->assertFalse(JsonOutput::parse('not json at all')['ok']);
        $this->assertFalse(JsonOutput::parse('   ')['ok']);
        $this->assertSame(['a' => ['b' => []]], JsonOutput::toArray(json_decode('{"a":{"b":[]}}')));
    }
}
