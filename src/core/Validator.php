<?php
/**
 * UniGo - Input validation.
 *
 * Every write endpoint validates server side. Rules are declared as
 * pipe separated strings, e.g. 'required|email|max:120'.
 * Nothing here trusts the client.
 */

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /** @var array<string,string[]> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $valid = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    /**
     * @param array<string,string> $rules  field => 'required|email|max:120'
     * @param array<string,string> $labels field => human readable name
     */
    public function validate(array $rules, array $labels = []): bool
    {
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $value = $this->data[$field] ?? null;
            if ($value !== null && !is_scalar($value)) {
                $this->addError($field, "$label must be a single value.");
                continue;
            }
            $this->runField($field, $label, (string) $value, explode('|', $ruleString));
        }
        return $this->errors === [];
    }

    private function runField(string $field, string $label, string $value, array $rules): void
    {
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $isEmpty = trim($value) === '';

            if ($name === 'required' && $isEmpty) {
                $this->addError($field, "$label is required.");
                return;
            }
            if ($name === 'nullable' && $isEmpty) {
                $this->valid[$field] = null;
                return;
            }
            if ($isEmpty) {
                continue; // skip remaining rules for empty optional fields
            }

            switch ($name) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->addError($field, "Enter a valid email address.");
                    }
                    break;
                case 'min':
                    $min = (int) $arg;
                    if (mb_strlen($value) < $min) {
                        $this->addError($field, "$label must be at least $min characters.");
                    }
                    break;
                case 'max':
                    $max = (int) $arg;
                    if (mb_strlen($value) > $max) {
                        $this->addError($field, "$label may not be longer than $max characters.");
                    }
                    break;
                case 'numeric':
                    if (!is_numeric($value)) {
                        $this->addError($field, "$label must be a number.");
                    }
                    break;
                case 'integer':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $this->addError($field, "$label must be a whole number.");
                    }
                    break;
                case 'min_value':
                    if (!is_numeric($value) || (float) $value < (float) $arg) {
                        $this->addError($field, "$label must be at least $arg.");
                    }
                    break;
                case 'max_value':
                    if (!is_numeric($value) || (float) $value > (float) $arg) {
                        $this->addError($field, "$label may not be greater than $arg.");
                    }
                    break;
                case 'in':
                    $allowed = explode(',', (string) $arg);
                    if (!in_array($value, $allowed, true)) {
                        $this->addError($field, "$label is not a valid selection.");
                    }
                    break;
                case 'regex':
                    if (!preg_match((string) $arg, $value)) {
                        $this->addError($field, "$label format is not valid.");
                    }
                    break;
                case 'date':
                    if (strtotime($value) === false) {
                        $this->addError($field, "$label must be a valid date.");
                    }
                    break;
                case 'after_or_equal':
                    if (strtotime($value) === false || strtotime($value) < strtotime((string) $arg)) {
                        $this->addError($field, "$label must be today or later.");
                    }
                    break;
                case 'latitude':
                    if (!self::isLatitude($value)) {
                        $this->addError($field, "$label must be a valid latitude.");
                    }
                    break;
                case 'longitude':
                    if (!self::isLongitude($value)) {
                        $this->addError($field, "$label must be a valid longitude.");
                    }
                    break;
                case 'phone':
                    if (!preg_match('/^\+?[0-9\s\-()]{7,20}$/', $value)) {
                        $this->addError($field, "$label must be a valid phone number.");
                    }
                    break;
                case 'password':
                    if (!$this->isStrongPassword($value)) {
                        $this->addError($field, self::passwordHint());
                    }
                    break;
                case 'confirmed':
                    $other = $this->data[$field . '_confirmation'] ?? ($this->data['password_confirmation'] ?? null);
                    if ($other !== $value) {
                        $this->addError($field, "$label confirmation does not match.");
                    }
                    break;
                case 'unique':
                    // usage: unique:table,column,ignore_id
                    [$table, $column, $ignore] = array_pad(explode(',', (string) $arg), 3, null);
                    $db = Database::instance();
                    $sql = "SELECT COUNT(*) FROM `$table` WHERE `$column` = ?";
                    $params = [$value];
                    if ($ignore !== null && $ignore !== '') {
                        $sql .= " AND id <> ?";
                        $params[] = (int) $ignore;
                    }
                    if ($db->count($sql, $params) > 0) {
                        $this->addError($field, "That $label is already registered.");
                    }
                    break;
                case 'exists':
                    // usage: exists:table,column
                    [$table, $column] = array_pad(explode(',', (string) $arg), 2, 'id');
                    if (!Database::instance()->exists("SELECT 1 FROM `$table` WHERE `$column` = ?", [$value])) {
                        $this->addError($field, "The selected $label is invalid.");
                    }
                    break;
                case 'same':
                    if ($value !== ($this->data[(string) $arg] ?? null)) {
                        $this->addError($field, "$label does not match.");
                    }
                    break;
                default:
                    // unknown rule is ignored on purpose (fail open on typos in
                    // rule names, but every rule name above is explicit)
                    break;
            }
        }

        $this->valid[$field] = $value;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }
        return null;
    }

    /** Validated (trimmed) values ready to be persisted. */
    public function validated(): array
    {
        return $this->valid;
    }

    // ------------------------------------------------------------------

    public static function isStrongPassword(string $value): bool
    {
        $min = (int) Config::get('security.password_min_length', 8);
        if (mb_strlen($value) < $min) {
            return false;
        }
        if (Config::get('security.password_require_mixed', true)) {
            if (!preg_match('/[a-z]/', $value) || !preg_match('/[A-Z0-9]/', $value)) {
                return false;
            }
        }
        return true;
    }

    public static function passwordHint(): string
    {
        return 'Password must be at least ' . (int) Config::get('security.password_min_length', 8)
            . ' characters and contain both lowercase and uppercase or numbers.';
    }

    public static function isLatitude(string $value): bool
    {
        return is_numeric($value) && (float) $value >= -90 && (float) $value <= 90;
    }

    public static function isLongitude(string $value): bool
    {
        return is_numeric($value) && (float) $value >= -180 && (float) $value <= 180;
    }

    /** Throw a 422 ValidationException carrying per field messages. */
    public function assert(array $rules, array $labels = []): array
    {
        if (!$this->validate($rules, $labels)) {
            throw new ValidationException(
                $this->firstError() ?? 'Please correct the highlighted fields.',
                0,
                null,
                $this->errors()
            );
        }
        return $this->validated();
    }
}
