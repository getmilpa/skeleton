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

    // ---- the words a person actually types --------------------------------------

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function booleans(): iterable
    {
        yield 'a real true' => [true, true];
        yield 'a real false' => [false, false];
        yield 'the string 1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'the string 0' => ['0', false];
        yield 'false' => ['false', false];
        yield 'no' => ['no', false];
        yield 'off' => ['off', false];
        yield 'an empty string' => ['', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('booleans')]
    public function testABooleanIsReadFromTheWordsAPersonActuallyTypes(mixed $raw, bool $expected): void
    {
        // PHP's own cast makes "0" true and "false" true. Either one silently
        // flips a flag the user set the other way.
        $typed = (new SchemaCoercer())->coerce(
            ['properties' => ['activo' => ['type' => 'boolean']]],
            ['activo' => $raw],
        );

        $this->assertSame($expected, $typed['activo']);
    }

    public function testAWordThatIsNotABooleanIsRefused(): void
    {
        $this->expectException(SchemaCoercionException::class);

        (new SchemaCoercer())->coerce(
            ['properties' => ['activo' => ['type' => 'boolean']]],
            ['activo' => 'quizá'],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notIntegers(): iterable
    {
        yield 'a decimal' => ['4.2'];
        yield 'a word' => ['muchos'];
        yield 'a number with a suffix' => ['42kg'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notIntegers')]
    public function testSomethingThatIsNotAWholeNumberIsRefused(string $raw): void
    {
        // A cast would turn "42kg" into 42 and "muchos" into 0 — a limit set to
        // nothing at all, with no complaint.
        $this->expectException(SchemaCoercionException::class);

        (new SchemaCoercer())->coerce(['properties' => ['n' => ['type' => 'integer']]], ['n' => $raw]);
    }

    public function testANegativeWholeNumberIsStillAnInteger(): void
    {
        $typed = (new SchemaCoercer())->coerce(
            ['properties' => ['n' => ['type' => 'integer']]],
            ['n' => '-7'],
        );

        $this->assertSame(-7, $typed['n']);
    }

    public function testANumberAcceptsDecimalsAndWholeNumbersAlike(): void
    {
        $typed = (new SchemaCoercer())->coerce(
            ['properties' => ['a' => ['type' => 'number'], 'b' => ['type' => 'number']]],
            ['a' => '4.2', 'b' => '7'],
        );

        $this->assertSame(4.2, $typed['a']);
        $this->assertSame(7.0, $typed['b']);
    }

    public function testSomethingThatIsNotANumberIsRefused(): void
    {
        $this->expectException(SchemaCoercionException::class);

        (new SchemaCoercer())->coerce(['properties' => ['a' => ['type' => 'number']]], ['a' => 'mucho']);
    }

    public function testAnArrayWhereAStringWasDeclaredIsRefused(): void
    {
        // A repeated query parameter arrives as an array. Stringifying it would
        // reach the operation as the word "Array".
        $this->expectException(SchemaCoercionException::class);

        (new SchemaCoercer())->coerce(
            ['properties' => ['nombre' => ['type' => 'string']]],
            ['nombre' => ['a', 'b']],
        );
    }

    public function testAnArrayFieldTakesTheArrayAsItIs(): void
    {
        $typed = (new SchemaCoercer())->coerce(
            ['properties' => ['tags' => ['type' => 'array']]],
            ['tags' => ['a', 'b']],
        );

        $this->assertSame(['a', 'b'], $typed['tags']);
    }

    public function testAStringWhereAnArrayWasDeclaredIsRefused(): void
    {
        $this->expectException(SchemaCoercionException::class);

        (new SchemaCoercer())->coerce(['properties' => ['tags' => ['type' => 'array']]], ['tags' => 'a,b']);
    }

    public function testAFieldWithNoDeclaredTypeIsTreatedAsAString(): void
    {
        $typed = (new SchemaCoercer())->coerce(['properties' => ['nombre' => []]], ['nombre' => 'Ana']);

        $this->assertSame('Ana', $typed['nombre']);
    }

    public function testAnOptionalFieldThatWasNotSentIsAbsentRatherThanNull(): void
    {
        // Not sent and sent empty are different, and the operation may care.
        $typed = (new SchemaCoercer())->coerce(['properties' => ['nota' => ['type' => 'string']]], []);

        $this->assertArrayNotHasKey('nota', $typed);
    }

    public function testARequiredFieldWithADefaultIsNotMissing(): void
    {
        $typed = (new SchemaCoercer())->coerce(
            ['properties' => ['limite' => ['type' => 'integer', 'default' => 5]], 'required' => ['limite']],
            [],
        );

        $this->assertSame(5, $typed['limite']);
    }

    public function testEveryProblemIsReportedAtOnceRatherThanOneAtATime(): void
    {
        // Fixing one field, re-running, and being told about the next is the
        // slowest possible way to correct a command line.
        try {
            (new SchemaCoercer())->coerce(
                [
                    'properties' => [
                        'n' => ['type' => 'integer'],
                        'activo' => ['type' => 'boolean'],
                        'nombre' => ['type' => 'string'],
                    ],
                    'required' => ['nombre'],
                ],
                ['n' => 'muchos', 'activo' => 'quizá'],
            );
            $this->fail('Expected the bad input to be refused.');
        } catch (SchemaCoercionException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('n', $message);
            $this->assertStringContainsString('activo', $message);
            $this->assertStringContainsString('nombre', $message);
        }
    }

    public function testAnInputWithNoPropertiesDeclaredCoercesToNothing(): void
    {
        $this->assertSame([], (new SchemaCoercer())->coerce([], ['lo' => 'que sea']));
    }
}
