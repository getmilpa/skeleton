<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SchemaCoercer;
use App\Command\SchemaCoercionException;
use PHPUnit\Framework\TestCase;

final class SchemaCoercerTest extends TestCase
{
    private SchemaCoercer $coercer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coercer = new SchemaCoercer();
    }

    public function testCoercesStringsToDeclaredTypes(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'ratio' => ['type' => 'number'],
            'live' => ['type' => 'boolean'],
        ]];

        $out = $this->coercer->coerce($schema, ['title' => 'Hi', 'count' => '7', 'ratio' => '1.5', 'live' => 'true']);

        self::assertSame('Hi', $out['title']);
        self::assertSame(7, $out['count']);
        self::assertSame(1.5, $out['ratio']);
        self::assertTrue($out['live']);
    }

    public function testAppliesDefaultsAndIgnoresUnknownRawKeys(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'default' => 'draft'],
        ]];

        $out = $this->coercer->coerce($schema, ['yes' => '1']);

        self::assertSame(['status' => 'draft'], $out);
    }

    public function testThrowsOnMissingRequired(): void
    {
        $schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']];

        try {
            $this->coercer->coerce($schema, []);
            self::fail('expected SchemaCoercionException');
        } catch (SchemaCoercionException $e) {
            self::assertCount(1, $e->errors);
            self::assertStringContainsString('title', $e->errors[0]);
        }
    }

    public function testThrowsOnEnumViolation(): void
    {
        $schema = ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['draft', 'live']]]];

        $this->expectException(SchemaCoercionException::class);
        $this->coercer->coerce($schema, ['status' => 'archived']);
    }
}
