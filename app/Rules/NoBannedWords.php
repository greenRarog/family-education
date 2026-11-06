<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\BannedWordChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoBannedWords implements ValidationRule
{
    public const MESSAGE = 'Текст содержит запрещённое слово. Измените формулировку.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(BannedWordChecker::class)->containsBannedWord($value)) {
            $fail(self::MESSAGE);
        }
    }
}
