<?php

namespace App\Validators;

class CustomTaskValidator
{
    public static function validateCreate(array $data): array
    {
        $errors = [];

        if (empty(trim((string)($data['title'] ?? '')))) {
            $errors['title'][] = 'عنوان الزامی است.';
        }

        if (empty(trim((string)($data['description'] ?? '')))) {
            $errors['description'][] = 'توضیحات الزامی است.';
        }

        $price = (float)($data['price_per_task'] ?? 0);
        if ($price <= 0) {
            $errors['price_per_task'][] = 'مبلغ هر تسک نامعتبر است.';
        }

        $qty = (int)($data['total_quantity'] ?? 0);
        if ($qty <= 0) {
            $errors['total_quantity'][] = 'تعداد باید بیشتر از صفر باشد.';
        }

        return $errors;
    }

    public static function validateProof(array $data): array
    {
        $errors = [];

        if (empty(trim((string)($data['proof_text'] ?? ''))) && empty($data['proof_image'])) {
            $errors['proof'][] = 'ارسال مدرک الزامی است.';
        }

        return $errors;
    }

    public static function validateDispute(array $data): array
    {
        $errors = [];

        if (empty(trim((string)($data['reason'] ?? '')))) {
            $errors['reason'][] = 'دلیل اختلاف الزامی است.';
        }

        return $errors;
    }
}