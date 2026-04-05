<?php
declare(strict_types=1);

namespace Core;

class Validator
{
    private array $data = [];
    private array $rules = [];
    private array $errors = [];

    public function __construct(array $data, array $rules = [])
    {
        $this->data = $data;
        $this->rules = [];

        if (!empty($rules)) {
            $this->validate($rules);
        }
    }

    public function validate(?array $rules = null): void
{
    // اگر rules پاس داده شد، ست کن
    if ($rules !== null) {
        $this->rules = $rules;
    }

    // اگر هنوز rules نداریم، به جای Fatal Error، خطای validator ثبت کن
    if (empty($this->rules)) {
        $this->addError('__validator', 'قوانین اعتبارسنجی ارسال نشده است.');
        return;
    }

    foreach ($this->rules as $field => $ruleString) {
        $fieldRules = \explode('|', (string)$ruleString);
        $value = $this->data[$field] ?? null;

        foreach ($fieldRules as $rule) {
            $this->applyRule($field, $value, $rule);
        }
    }
}

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $param = null;

        if (\strpos($rule, ':') !== false) {
            [$ruleName, $param] = \explode(':', $rule, 2);
        } else {
            $ruleName = $rule;
        }

        switch ($ruleName) {
            case 'required':
                if ($value === null || $value === '' || (\is_array($value) && empty($value))) {
                    $this->addError($field, 'این فیلد الزامی است');
                }
                break;

            case 'email':
                if ($value !== null && $value !== '' && !\filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, 'ایمیل نامعتبر است');
                }
                break;

            case 'min':
                if ($value !== null && $value !== '') {
                    $min = (int)$param;
                    if (\mb_strlen((string)$value) < $min) {
                        $this->addError($field, "حداقل {$min} کاراکتر مجاز است");
                    }
                }
                break;

            case 'max':
                if ($value !== null && $value !== '') {
                    $max = (int)$param;
                    if (\mb_strlen((string)$value) > $max) {
                        $this->addError($field, "حداکثر {$max} کاراکتر مجاز است");
                    }
                }
                break;

            case 'in':
                if ($value !== null && $value !== '') {
                    $allowed = \explode(',', (string)$param);
                    if (!\in_array((string)$value, $allowed, true)) {
                        $this->addError($field, 'مقدار نامعتبر است');
                    }
                }
                break;

            case 'date':
                if ($value !== null && $value !== '' && \strtotime((string)$value) === false) {
                    $this->addError($field, 'تاریخ نامعتبر است');
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    // قانون 20
    public function data(): array
    {
        return $this->data;
    }

    public function all(): array
    {
        return $this->data;
    }
}