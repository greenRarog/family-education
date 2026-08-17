<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BannedWord;

class BannedWordChecker
{
    public function containsBannedWord(string $text): bool
    {
        foreach (BannedWord::query()->pluck('word') as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            $pattern = '/(?<![\\pL\\pN_])'.preg_quote($word, '/').'(?![\\pL\\pN_])/ui';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
