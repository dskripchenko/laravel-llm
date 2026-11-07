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
            'type' => ['type' => 'string', 'enum' => ['guide', 'reference']],
            'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 10],
            'score' => ['type' => 'number'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 5], 'maxItems' => 2],
            'author' => ['type' => ['string', 'null']],
            'meta' => [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean']],
                'required' => ['ok'],
            ],
        ],
        'required' => ['type', 'level'],
        'additionalProperties' => false,
    ];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'valid' => ['{"type":"guide","level":2,"title":"Setup","score":0.5,"tags":["ui"],"author":null,"meta":{"ok":true}}', []];
        yield 'integer as 2.0 is an integer' => ['{"type":"guide","level":2.0}', []];
        yield 'missing required' => ['{"type":"guide"}', ['$: missing required property `level`']];
        yield 'enum' => ['{"type":"poem","level":1}', ['$.type: must be one of "guide", "reference", got "poem"']];
        yield 'type' => ['{"type":"guide","level":"high"}', ['$.level: expected integer, got string']];
        yield 'minimum' => ['{"type":"guide","level":0}', ['$.level: must be >= 1']];
        yield 'maximum' => ['{"type":"guide","level":9}', ['$.level: must be <= 4']];
        yield 'minLength' => ['{"type":"guide","level":1,"title":"ab"}', ['$.title: must be at least 3 characters long']];
        yield 'maxLength counts characters' => ['{"type":"guide","level":1,"title":"длинноеимяя"}', ['$.title: must be at most 10 characters long']];
        yield 'items' => ['{"type":"guide","level":1,"tags":["ui",7]}', ['$.tags[1]: expected string, got integer']];
        yield 'maxItems' => ['{"type":"guide","level":1,"tags":["a","b","c"]}', ['$.tags: must have at most 2 item(s)']];
        yield 'nullable union' => ['{"type":"guide","level":1,"author":5}', ['$.author: expected string or null, got integer']];
        yield 'nested required' => ['{"type":"guide","level":1,"meta":{}}', ['$.meta: missing required property `ok`']];
        yield 'empty object is not an array' => ['{"type":"guide","level":1,"tags":{}}', ['$.tags: expected array, got object']];
        yield 'additional property' => ['{"type":"guide","level":1,"extra":1}', ['$: unexpected property `extra`']];
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
