<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests\Api;

use AaiEduHr\SimbiozaModuleWorkspace\Api\WorkspaceResourceController;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * EN: Distinguishes JSON objects from lists without losing empty objects or nested arrays.
 *
 * HR: Razlikuje JSON objekte od lista bez gubitka praznih objekata i ugniježđenih nizova.
 */
#[CoversClass(WorkspaceResourceController::class)]
final class ApiJsonObjectBodyTest extends TestCase
{
    /**
     * EN: Supplies valid named-field objects.
     *
     * HR: Daje valjane objekte s imenovanim poljima.
     *
     * @return \Generator<string, array{string, array<string, mixed>}>
     */
    public static function validObjects(): \Generator
    {
        yield 'empty object' => ['{}', []];
        yield 'whitespace' => [" \n{}\t", []];
        yield 'named fields' => ['{"title":"Workshop","confirmed":true}', ['title' => 'Workshop', 'confirmed' => true]];
        yield 'nested collections' => [
            '{"choices":[],"attributes":{"0":"nested"}}',
            ['choices' => [], 'attributes' => [0 => 'nested']],
        ];
    }

    /**
     * EN: Preserves supported JSON objects.
     *
     * HR: Čuva podržane JSON objekte.
     *
     * @param array<string, mixed> $expected
     */
    #[DataProvider('validObjects')]
    public function testAcceptsNamedFieldObjects(string $json, array $expected): void
    {
        $this->assertSame($expected, $this->parse($json));
    }

    /**
     * EN: Lists invalid top-level values.
     *
     * HR: Navodi nevaljane vršne vrijednosti.
     *
     * @return \Generator<string, array{string}>
     */
    public static function invalidValues(): \Generator
    {
        yield 'empty list' => ['[]'];
        yield 'nonempty list' => ['[1]'];
        yield 'string' => ['"text"'];
        yield 'number' => ['42'];
        yield 'null' => ['null'];
        yield 'numeric object key' => ['{"0":"unsupported"}'];
        yield 'mixed numeric key' => ['{"title":"Workshop","0":"unsupported"}'];
    }

    /**
     * EN: Rejects lists, scalars and numeric field names.
     *
     * HR: Odbija liste, skalare i numeričke nazive polja.
     */
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidTopLevelValues(string $json): void
    {
        $this->expectException(JsonException::class);
        $this->parse($json);
    }

    /**
     * EN: Retains the existing no-body contract.
     *
     * HR: Zadržava postojeći ugovor zahtjeva bez tijela.
     */
    public function testPreservesNoBodyContract(): void
    {
        $this->assertSame([], $this->parse('   '));
    }

    /**
     * EN: Syntax errors remain failures.
     *
     * HR: Sintaktičke pogreške ostaju neuspješne.
     */
    public function testRejectsMalformedJson(): void
    {
        $this->expectException(JsonException::class);
        $this->parse('{');
    }

    /**
     * EN: Exercises the actual parser without application/bootstrap side effects.
     *
     * HR: Provjerava stvarni parser bez nuspojava aplikacijskog bootstrapa.
     *
     * @return array<array-key, mixed>
     */
    private function parse(string $json): array
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn($json);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($body);
        $controller = (new ReflectionClass(WorkspaceResourceController::class))->newInstanceWithoutConstructor();
        $parser = new ReflectionMethod(WorkspaceResourceController::class, 'jsonBody');
        $value = $parser->invoke($controller, $request);
        $this->assertIsArray($value);
        return $value;
    }
}
