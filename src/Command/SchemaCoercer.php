<?php

declare(strict_types=1);

namespace App\Command;

/**
 * Coerces a raw string-keyed input bag into typed values per a JSON-Schema-shaped inputSchema,
 * applying defaults and validating required/enum. A minimal, dependency-free stand-in for
 * tool-runtime's SchemaValidator (which is suggest-only in the skeleton) so the CLI and HTTP
 * projectors can type inputs without pulling the agent surface.
 */
final class SchemaCoercer
{
    /**
     * @param array<string, mixed>                           $inputSchema JSON-Schema-shaped
     * @param array<string, string|array<int|string, mixed>> $raw         raw string/array inputs
     *
     * @return array<string, mixed> typed input
     *
     * @throws SchemaCoercionException
     */
    public function coerce(array $inputSchema, array $raw): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = \is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        /** @var list<string> $required */
        $required = \is_array($inputSchema['required'] ?? null) ? array_values($inputSchema['required']) : [];

        $out = [];
        $errors = [];

        foreach ($properties as $name => $spec) {
            $type = \is_string($spec['type'] ?? null) ? $spec['type'] : 'string';

            if (!\array_key_exists($name, $raw)) {
                if (\array_key_exists('default', $spec)) {
                    $out[$name] = $spec['default'];
                } elseif (\in_array($name, $required, true)) {
                    $errors[] = "missing required field '{$name}'";
                }
                continue;
            }

            try {
                $value = $this->coerceValue($raw[$name], $type);
            } catch (\InvalidArgumentException $e) {
                $errors[] = "field '{$name}': " . $e->getMessage();
                continue;
            }

            if (isset($spec['enum']) && \is_array($spec['enum']) && !\in_array($value, $spec['enum'], true)) {
                $errors[] = "field '{$name}': value not allowed";
                continue;
            }

            $out[$name] = $value;
        }

        if ($errors !== []) {
            throw new SchemaCoercionException($errors);
        }

        return $out;
    }

    private function coerceValue(mixed $raw, string $type): mixed
    {
        return match ($type) {
            'integer' => $this->toInt($raw),
            'number' => $this->toFloat($raw),
            'boolean' => $this->toBool($raw),
            'array' => \is_array($raw) ? $raw : throw new \InvalidArgumentException('expected an array'),
            default => \is_scalar($raw) ? (string) $raw : throw new \InvalidArgumentException('expected a string'),
        };
    }

    private function toInt(mixed $raw): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        throw new \InvalidArgumentException('expected an integer');
    }

    private function toFloat(mixed $raw): float
    {
        if (\is_int($raw) || \is_float($raw)) {
            return (float) $raw;
        }
        if (\is_string($raw) && is_numeric($raw)) {
            return (float) $raw;
        }
        throw new \InvalidArgumentException('expected a number');
    }

    private function toBool(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        if (\in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($raw, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        throw new \InvalidArgumentException('expected a boolean');
    }
}
