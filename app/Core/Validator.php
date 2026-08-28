<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rules are declared as 'field' => 'required|min:3|max:255'.
 * Unknown rules throw, so a typo fails loudly instead of silently passing.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
        $this->run();
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleset) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleset);
            $optional = !in_array('required', $rules, true);

            if ($optional && ($value === null || $value === '')) {
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                $this->apply($field, $value, $name, $argument);
            }
        }
    }

    private function apply(string $field, mixed $value, string $rule, ?string $argument): void
    {
        $label = $this->labels[$field] ?? str_replace('_', ' ', $field);

        match ($rule) {
            'required' => $this->check(
                $value !== null && $value !== '' && $value !== [],
                $field,
                ucfirst($label) . ' is required.'
            ),
            'email' => $this->check(
                filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false,
                $field,
                'Enter a valid email address.'
            ),
            'min' => $this->check(
                mb_strlen((string) $value) >= (int) $argument,
                $field,
                ucfirst($label) . " must be at least {$argument} characters."
            ),
            'max' => $this->check(
                mb_strlen((string) $value) <= (int) $argument,
                $field,
                ucfirst($label) . " must be at most {$argument} characters."
            ),
            'integer' => $this->check(
                filter_var($value, FILTER_VALIDATE_INT) !== false,
                $field,
                ucfirst($label) . ' must be a whole number.'
            ),
            'in' => $this->check(
                in_array((string) $value, explode(',', (string) $argument), true),
                $field,
                ucfirst($label) . ' is not one of the allowed values.'
            ),
            'matches' => $this->check(
                (string) $value === (string) ($this->data[(string) $argument] ?? null),
                $field,
                ucfirst($label) . ' does not match.'
            ),
            'slug' => $this->check(
                preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) === 1,
                $field,
                ucfirst($label) . ' may only contain lowercase letters, numbers and hyphens.'
            ),
            'url' => $this->check(
                filter_var((string) $value, FILTER_VALIDATE_URL) !== false,
                $field,
                'Enter a valid URL.'
            ),
            default => throw new \InvalidArgumentException("Unknown validation rule [{$rule}]."),
        };
    }

    private function check(bool $passed, string $field, string $message): void
    {
        if (!$passed) {
            $this->errors[$field][] = $message;
        }
    }
}
