<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;

/**
 * Declarative server-side input validation.
 *
 * Supported rules (single | separated string):
 *   required, nullable, string, email, integer, numeric, decimal:2,
 *   min:N, max:N (length for strings, value for numbers),
 *   in:a,b,c, confirmed, slug, boolean, date, url, array,
 *   unique:table,column[,ignoreId] (database check),
 *   exists:table,column (database check)
 */
final class Validator
{
    private array $data;
    private array $errors = [];
    /** @var array<string,list<string>> field => rule list (for numeric detection in min/max) */
    private array $fieldRules = [];

    public function __construct(array $data, private readonly array $rules, private readonly array $labels = [])
    {
        $this->data = $data;
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    /**
     * @throws ValidationException on the first rule set failure (all errors collected)
     */
    public function validate(): array
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $this->fieldRules[$field] = $rules;
            $value = $this->data[$field] ?? null;
            $label = $this->labels[$field] ?? ucwords(str_replace('_', ' ', $field));
            $nullable = in_array('nullable', $rules, true);

            $present = $value !== null && $value !== [];
            if ($value === '') {
                $present = false;
            }

            if (in_array('required', $rules, true)) {
                if (!$present) {
                    $this->addError($field, "{$label} is required.");
                    continue;
                }
            } elseif (!$present) {
                if ($nullable || !in_array('required', $rules, true)) {
                    continue; // absent + not required: skip other rules
                }
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                $this->apply($field, $label, $rule, $value);
                if (isset($this->errors[$field])) {
                    break;
                }
            }
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->data;
    }

    private function apply(string $field, string $label, string $rule, mixed $value): void
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, "{$label} must be text.");
                }
                break;
            case 'email':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "{$label} must be a valid email address.");
                }
                break;
            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false || is_bool($value)) {
                    $this->addError($field, "{$label} must be a whole number.");
                }
                break;
            case 'numeric':
                if (is_string($value) && !is_numeric($value)) {
                    $this->addError($field, "{$label} must be a number.");
                } elseif (!is_int($value) && !is_float($value) && !is_string($value)) {
                    $this->addError($field, "{$label} must be a number.");
                }
                break;
            case 'decimal':
                $digits = $arg === null ? 2 : (int) $arg;
                if (is_string($value) && !preg_match('/^-?\d+(\.\d{1,' . $digits . '})?$/', $value)) {
                    $this->addError($field, "{$label} must be a valid amount with at most {$digits} decimal places.");
                }
                break;
            case 'min':
                $isNum = $this->isNumericField($field);
                if (is_string($value) && $isNum && is_numeric($value) && (float) $value < (int) $arg) {
                    $this->addError($field, "{$label} must be at least {$arg}.");
                } elseif (is_string($value) && !$isNum && mb_strlen($value) < (int) $arg) {
                    $this->addError($field, "{$label} must be at least {$arg} characters.");
                } elseif ((is_int($value) || is_float($value)) && $value < (int) $arg) {
                    $this->addError($field, "{$label} must be at least {$arg}.");
                }
                break;
            case 'max':
                $isNum = $this->isNumericField($field);
                if (is_string($value) && $isNum && is_numeric($value) && (float) $value > (int) $arg) {
                    $this->addError($field, "{$label} must be at most {$arg}.");
                } elseif (is_string($value) && !$isNum && mb_strlen($value) > (int) $arg) {
                    $this->addError($field, "{$label} may not exceed {$arg} characters.");
                } elseif ((is_int($value) || is_float($value)) && $value > (int) $arg) {
                    $this->addError($field, "{$label} may not exceed {$arg}.");
                }
                break;
            case 'in':
                $options = explode(',', (string) $arg);
                if (!in_array((string) $value, $options, true)) {
                    $this->addError($field, "{$label} is invalid.");
                }
                break;
            case 'confirmed':
                $confirm = $this->data[$field . '_confirmation'] ?? null;
                if ($confirm !== $value) {
                    $this->addError($field, "{$label} confirmation does not match.");
                }
                break;
            case 'slug':
                if (!is_string($value) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
                    $this->addError($field, "{$label} may only contain lowercase letters, numbers and dashes.");
                }
                break;
            case 'boolean':
                if (!in_array($value, [true, false, 0, 1, '0', '1', 'on'], true)) {
                    $this->addError($field, "{$label} must be true or false.");
                }
                break;
            case 'date':
                if (!is_string($value) || strtotime($value) === false) {
                    $this->addError($field, "{$label} must be a valid date.");
                }
                break;
            case 'url':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, "{$label} must be a valid URL.");
                }
                break;
            case 'array':
                if (!is_array($value)) {
                    $this->addError($field, "{$label} is invalid.");
                }
                break;
            case 'unique':
                [$table, $column, $ignoreId] = array_pad(explode(',', (string) $arg), 3, null);
                if ($this->valueTaken((string) $table, $column ?: $field, $value, $ignoreId)) {
                    $this->addError($field, "{$label} is already in use.");
                }
                break;
            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $arg), 2, null);
                if (!$this->valueExists((string) $table, $column ?: $field, $value)) {
                    $this->addError($field, "{$label} does not exist.");
                }
                break;
        }
    }

    /**
     * Check if the field's rule list contains 'integer', 'decimal',
     * or 'numeric' — indicating that min/max should compare
     * numerically instead of by string length.
     */
    private function isNumericField(string $field): bool
    {
        $rules = $this->fieldRules[$field] ?? [];
        foreach ($rules as $r) {
            if (str_starts_with($r, 'integer') || str_starts_with($r, 'decimal') || str_starts_with($r, 'numeric')) {
                return true;
            }
        }
        return false;
    }

    private function valueTaken(string $table, string $column, mixed $value, ?string $ignoreId): bool
    {
        $allowed = ['users', 'roles', 'permissions', 'brands', 'categories', 'product_models',
            'inventory_units', 'customers', 'orders', 'locations', 'settings', 'product_images'];
        if (!in_array($table, $allowed, true)) {
            throw new \RuntimeException("unique rule not allowed for table {$table}");
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
        $params = [$value];
        if ($ignoreId !== null && $ignoreId !== '') {
            $sql .= " AND id != ?";
            $params[] = $ignoreId;
        }
        $stmt = \App\Support\Database\Connection::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function valueExists(string $table, string $column, mixed $value): bool
    {
        $allowed = ['users', 'roles', 'permissions', 'brands', 'categories', 'product_models',
            'inventory_units', 'customers', 'orders', 'locations'];
        if (!in_array($table, $allowed, true)) {
            throw new \RuntimeException("exists rule not allowed for table {$table}");
        }
        $stmt = \App\Support\Database\Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?"
        );
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }
}
