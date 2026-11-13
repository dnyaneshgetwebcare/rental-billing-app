<?php

declare(strict_types=1);

namespace App\Shared;

use App\Gst\GstCalculator;
use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads and validates request fields, collecting all errors before failing:
 *
 *   $in = Input::fromRequest($request);
 *   $name = $in->string('name', required: true, max: 200);
 *   $in->validate(); // throws ApiException (422) with per-field messages
 */
final class Input
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data, private readonly string $prefix = '') {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        return new self(is_array($body) ? $body : []);
    }

    public static function fromQuery(ServerRequestInterface $request): self
    {
        return new self($request->getQueryParams());
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, bool $required = false, int $max = 255, ?string $default = null): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $this->error($key, 'This field is required.');
            }
            return $default;
        }
        if (!is_scalar($value)) {
            $this->error($key, 'Must be text.');
            return $default;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            $this->error($key, "Must be at most $max characters.");
        }
        return $value;
    }

    public function int(string $key, bool $required = false, ?int $min = null, ?int $max = null, ?int $default = null): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->error($key, 'This field is required.');
            }
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->error($key, 'Must be a whole number.');
            return $default;
        }
        $value = (int) $value;
        if ($min !== null && $value < $min) {
            $this->error($key, "Must be at least $min.");
        }
        if ($max !== null && $value > $max) {
            $this->error($key, "Must be at most $max.");
        }
        return $value;
    }

    public function money(string $key, bool $required = false, bool $allowNegative = false, ?string $default = null, int $scale = 2): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->error($key, 'This field is required.');
            }
            return $default;
        }
        if (!Money::isValid($value)) {
            $this->error($key, 'Must be a number.');
            return $default;
        }
        $amount = Money::of($value, $scale);
        if (!$allowNegative && Money::cmp($amount, '0') < 0) {
            $this->error($key, 'Cannot be negative.');
        }
        return $amount;
    }

    public function date(string $key, bool $required = false, ?string $default = null): ?string
    {
        $value = $this->string($key, $required, 10);
        if ($value === null) {
            return $default;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            $this->error($key, 'Must be a date (YYYY-MM-DD).');
            return $default;
        }
        return $value;
    }

    public function period(string $key, bool $required = false): ?string
    {
        $value = $this->string($key, $required, 7);
        if ($value !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            $this->error($key, 'Must be a month (YYYY-MM).');
            return null;
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null || $this->data[$key] === '') {
            return $default;
        }
        return filter_var($this->data[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $key, array $allowed, bool $required = false, ?string $default = null): ?string
    {
        $value = $this->string($key, $required, 50);
        if ($value === null) {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            $this->error($key, 'Must be one of: ' . implode(', ', $allowed) . '.');
            return $default;
        }
        return $value;
    }

    public function email(string $key, bool $required = false): ?string
    {
        $value = $this->string($key, $required, 150);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->error($key, 'Must be a valid email address.');
        }
        return $value === null ? null : strtolower($value);
    }

    public function gstin(string $key, bool $required = false): ?string
    {
        $value = $this->string($key, $required, 15);
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (!GstCalculator::isValidGstin($value)) {
            $this->error($key, 'Must be a valid 15-character GSTIN.');
        }
        return $value;
    }

    public function stateCode(string $key, bool $required = false): ?string
    {
        $value = $this->string($key, $required, 2);
        if ($value === null) {
            return null;
        }
        $value = GstCalculator::normalizeState($value);
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 99) {
            $this->error($key, 'Must be a 2-digit GST state code.');
        }
        return $value;
    }

    /**
     * Nested list of objects, e.g. invoice lines. Errors are reported as "lines.0.rate".
     *
     * @return list<self>
     */
    public function list(string $key, bool $required = false, int $max = 200): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || $value === []) {
            if ($required) {
                $this->error($key, 'Add at least one line.');
            }
            return [];
        }
        if (count($value) > $max) {
            $this->error($key, "At most $max lines.");
        }
        $out = [];
        foreach (array_values($value) as $i => $row) {
            $out[] = new self(is_array($row) ? $row : [], $this->prefix . $key . '.' . $i . '.');
        }
        return $out;
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$this->prefix . $key][] = $message;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param list<self> $children
     */
    public function validate(array $children = []): void
    {
        $errors = $this->errors;
        foreach ($children as $child) {
            $errors += $child->errors();
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }
}
