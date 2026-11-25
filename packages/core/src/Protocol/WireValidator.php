<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Protocol;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Validates Office SDK v1 wire envelopes against contracts/office-v1.schema.json (Draft 2020-12)
 * and enforces the three HTTP transport validation boundaries.
 */
final class WireValidator
{
    /**
     * The largest operation body read. Requests are small by design (reads and results travel in the
     * response); the bound keeps a client from making the server decode megabytes of JSON.
     */
    public const int MAX_HTTP_BODY_BYTES = 1_048_576;

    private readonly stdClass $schema;

    private readonly Validator $validator;

    public function __construct(?string $schemaPath = null)
    {
        $path = $schemaPath ?? dirname(__DIR__, 2).'/resources/contracts/office-v1.schema.json';
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException("Unable to read Office schema file at [{$path}].");
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in Office schema file at [{$path}].", 0, $e);
        }

        if (! $decoded instanceof stdClass) {
            throw new RuntimeException("Office schema root at [{$path}] must be a JSON object.");
        }

        $this->schema = $decoded;
        $this->validator = new Validator;
    }

    /**
     * Validate any wire message (request, success, failure) against office-v1.schema.json.
     */
    public function isValidMessage(stdClass $message): bool
    {
        return $this->validator->validate($message, $this->schema)->isValid();
    }

    public function assertValidMessage(stdClass $message): void
    {
        $result = $this->validator->validate($message, $this->schema);
        if (! $result->isValid()) {
            $error = $result->error();
            $formatted = $error !== null ? json_encode((new ErrorFormatter)->format($error), JSON_UNESCAPED_UNICODE) : 'unknown';
            throw new RuntimeException('Office wire message failed schema validation: '.$formatted);
        }
    }

    public function isValidRequest(stdClass $envelope): bool
    {
        return ! property_exists($envelope, 'ok')
            && ! property_exists($envelope, 'result')
            && ! property_exists($envelope, 'error')
            && $this->isValidMessage($envelope);
    }

    public function toolInputSchema(OfficeOperation $operation): stdClass
    {
        $name = substr($operation->value, strlen('office.')).'Input';
        $definitions = $this->schema->{'$defs'} ?? null;
        if (! $definitions instanceof stdClass) {
            throw new RuntimeException('Office schema definitions are missing.');
        }
        $input = $definitions->{$name} ?? null;
        if (! $input instanceof stdClass) {
            throw new RuntimeException('Office tool input schema is missing.');
        }

        $used = $this->inputDefinitions($definitions, [$name => true], $input);
        $subset = array_intersect_key(get_object_vars($definitions), $used);
        if ($operation === OfficeOperation::Commit) {
            $commit = clone $input;
            $required = $commit->required ?? null;
            if (is_array($required)) {
                $commit->required = array_values(array_filter($required, static fn (mixed $field): bool => $field !== 'command_id'));
            }
            $subset[$name] = $commit;
        }
        $schema = json_decode(json_encode((object) [
            '$ref' => '#/$defs/'.$name,
            '$defs' => $subset,
        ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return $schema instanceof stdClass ? $schema : throw new RuntimeException('Malformed Office tool input schema.');
    }

    public function isValidToolArguments(stdClass $arguments, stdClass $schema): bool
    {
        return $this->validator->validate($arguments, $schema)->isValid();
    }

    /** @param array<string,true> $used
     * @return array<string,true>
     */
    private function inputDefinitions(stdClass $definitions, array $used, mixed $node): array
    {
        if ($node instanceof stdClass) {
            $ref = $node->{'$ref'} ?? null;
            if (is_string($ref) && str_starts_with($ref, '#/$defs/')) {
                $name = substr($ref, strlen('#/$defs/'));
                if (! isset($used[$name])) {
                    $used[$name] = true;
                    $used = $this->inputDefinitions($definitions, $used, $definitions->{$name} ?? null);
                }
            }
            $node = get_object_vars($node);
        }
        if (is_array($node)) {
            foreach ($node as $child) {
                $used = $this->inputDefinitions($definitions, $used, $child);
            }
        }

        return $used;
    }

    /**
     * Parse raw HTTP JSON body following the three transport boundaries from agent-sdk.md:
     *  1. Malformed JSON, non-object, missing string operation, or unknown operation => transport 400 {"error":"invalid_request"}
     *  2. Known operation with integer version !== 1 => HTTP 400 v1 envelope with unsupported_version
     *  3. Known operation v1 failing schema (missing/bad fields, extra envelope keys) => HTTP 400 v1 envelope with invalid_input
     *
     * A body above MAX_HTTP_BODY_BYTES is refused before it is decoded (transport 413).
     *
     * @return array{ok: true, request: OfficeRequest}|array{ok: false, kind: 'invalid_request'}|array{ok: false, kind: 'too_large'}|array{ok: false, kind: 'envelope', operation: OfficeOperation, error_code: ErrorCode}
     */
    public function parseHttpPayload(string $rawBody): array
    {
        if (strlen($rawBody) > self::MAX_HTTP_BODY_BYTES) {
            return ['ok' => false, 'kind' => 'too_large'];
        }
        if (trim($rawBody) === '') {
            return ['ok' => false, 'kind' => 'invalid_request'];
        }

        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ok' => false, 'kind' => 'invalid_request'];
        }

        if (! $decoded instanceof stdClass) {
            return ['ok' => false, 'kind' => 'invalid_request'];
        }

        if (! property_exists($decoded, 'operation') || ! is_string($decoded->operation)) {
            return ['ok' => false, 'kind' => 'invalid_request'];
        }

        $operation = OfficeOperation::tryFrom($decoded->operation);
        if ($operation === null) {
            return ['ok' => false, 'kind' => 'invalid_request'];
        }

        if (property_exists($decoded, 'version') && is_int($decoded->version) && $decoded->version !== 1) {
            return [
                'ok' => false,
                'kind' => 'envelope',
                'operation' => $operation,
                'error_code' => ErrorCode::UnsupportedVersion,
            ];
        }

        if (
            property_exists($decoded, 'ok')
            || property_exists($decoded, 'result')
            || property_exists($decoded, 'error')
            || ! $this->isValidMessage($decoded)
            || ! property_exists($decoded, 'input')
            || ! $decoded->input instanceof stdClass
        ) {
            return [
                'ok' => false,
                'kind' => 'envelope',
                'operation' => $operation,
                'error_code' => ErrorCode::InvalidInput,
            ];
        }

        return [
            'ok' => true,
            'request' => new OfficeRequest(
                version: 1,
                operation: $operation,
                input: $decoded->input,
            ),
        ];
    }
}
