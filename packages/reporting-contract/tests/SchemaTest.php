<?php

declare(strict_types=1);

namespace EdutrustPay\Contract\Tests;

use EdutrustPay\Contract\ContractVersion;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the published JSON Schema against the published fixtures.
 *
 * The schema is what implementers outside PHP build against, so an incorrect one
 * is worse than none: it tells someone their payload is fine when the server
 * will refuse it, and they find out at month end. Running it here means the
 * schema is verified rather than merely written.
 *
 * A note on the validator choice, because it cost a real bug: the schema uses
 * draft-07 if/then/else to express the capability coupling, and
 * justinrainbow/json-schema SILENTLY IGNORES those keywords — it reports such a
 * schema as satisfied no matter what. The coupling rules would have been inert
 * while appearing tested. opis/json-schema implements draft-07 properly, and
 * test_the_schema_actually_enforces_if_then below is a canary that fails loudly
 * if the validator is ever swapped for one that does not.
 */
final class SchemaTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function test_fixture_validates_against_the_published_schema(string $name): void
    {
        $document = json_decode((string) file_get_contents(__DIR__.'/../fixtures/'.$name.'.json'), true);
        $result = $this->validate(json_decode($document['canonical_json']));

        $error = $result->error();

        $this->assertTrue(
            $result->isValid(),
            "Fixture '$name' does not satisfy the published schema:\n"
            .($error === null ? '' : json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT))
        );
    }

    /**
     * The canary. If this fails, the schema's capability coupling is not being
     * checked at all and every other assertion in this file is worthless.
     */
    public function test_the_schema_actually_enforces_if_then(): void
    {
        $schema = json_decode(json_encode([
            'type' => 'object',
            'allOf' => [[
                'if' => ['properties' => ['caps' => ['contains' => ['const' => 'x']]]],
                'then' => ['required' => ['blk']],
            ]],
        ]));

        $validator = new JsonSchemaValidator();

        $this->assertFalse(
            $validator->validate(json_decode('{"caps":["x"]}'), $schema)->isValid(),
            'This JSON Schema validator is ignoring if/then. The capability coupling rules in the '
            .'published schema are therefore not being enforced, and the rest of this file proves nothing.'
        );

        $this->assertTrue($validator->validate(json_decode('{"caps":["y"]}'), $schema)->isValid());
    }

    public function test_the_schema_refuses_a_capability_declared_without_its_figures(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->capabilities[] = 'receivables';

        $this->assertFalse(
            $this->validate($payload)->isValid(),
            'Declaring a capability the institution has supplied no figures for must be refused.'
        );
    }

    public function test_the_schema_refuses_figures_without_the_matching_capability(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->financial->receivables = (object) [
            'total' => '100.00',
            'ageing' => (object) ['0_30' => '100.00', '31_60' => '0.00', '61_90' => '0.00', '90_plus' => '0.00'],
        ];

        $this->assertFalse(
            $this->validate($payload)->isValid(),
            'Figures the console cannot attribute to a declared capability must be refused.'
        );
    }

    public function test_the_schema_accepts_a_declared_capability_that_is_genuinely_zero(): void
    {
        // The other side of the same rule: 0.00 is a perfectly good answer when
        // the institution can actually measure the thing.
        $this->assertTrue($this->validate($this->payload('genuine-zeros'))->isValid());
    }

    public function test_the_schema_refuses_money_as_a_json_number(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->financial->surplus_deficit = 600000;

        $this->assertFalse($this->validate($payload)->isValid());
    }

    public function test_the_schema_refuses_a_fiscal_year_as_a_period(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->period = '2026';

        $this->assertFalse($this->validate($payload)->isValid());
    }

    public function test_the_schema_refuses_an_ohada_group_that_is_not_two_digits(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->financial->income_by_group = (object) ['7' => '1800000.00'];

        $this->assertFalse($this->validate($payload)->isValid());
    }

    public function test_the_schema_refuses_an_empty_capability_list(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->capabilities = [];

        $this->assertFalse($this->validate($payload)->isValid());
    }

    public function test_the_schema_carries_a_coupling_rule_for_every_capability(): void
    {
        $schema = json_decode((string) file_get_contents(
            __DIR__.'/../schema/report.v'.ContractVersion::CURRENT.'.json'
        ), true);

        $covered = [];

        foreach ($schema['allOf'] as $rule) {
            $covered[] = $rule['if']['properties']['capabilities']['contains']['const'];
        }

        sort($covered);
        $expected = \EdutrustPay\Contract\Capability::all();
        sort($expected);

        // The schema is generated from Capability::blocks(); this catches a
        // capability added to the code but never regenerated into the schema,
        // which would leave implementers building against a stale contract.
        $this->assertSame($expected, $covered, 'Run tools/generate-schema.php — the schema is out of date.');
    }

    public function test_the_schema_refuses_an_unknown_key_inside_a_group_map(): void
    {
        $payload = $this->payload('ledger-only');
        $payload->financial->income_by_group = (object) ['not_a_group' => '1800000.00'];

        $this->assertFalse($this->validate($payload)->isValid());
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function fixtures(): array
    {
        $index = json_decode((string) file_get_contents(__DIR__.'/../fixtures/index.json'), true);

        return array_map(static fn (string $name): array => [$name], array_keys($index));
    }

    private function payload(string $name): object
    {
        $document = json_decode((string) file_get_contents(__DIR__.'/../fixtures/'.$name.'.json'), true);

        return json_decode($document['canonical_json']);
    }

    private function validate(object $payload): \Opis\JsonSchema\ValidationResult
    {
        $schema = json_decode((string) file_get_contents(
            __DIR__.'/../schema/report.v'.ContractVersion::CURRENT.'.json'
        ));

        return (new JsonSchemaValidator())->validate($payload, $schema);
    }
}
