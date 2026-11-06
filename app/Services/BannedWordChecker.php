<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BannedWord;
use Normalizer;

class BannedWordChecker
{
    public function containsBannedWord(string $text): bool
    {
        $text = Normalizer::normalize($text) ?: $text;

        foreach (BannedWord::query()->pluck('word') as $word) {
            $word = Normalizer::normalize(trim($word)) ?: trim($word);

            if ($word === '') {
                continue;
            }

            $pattern = '/(?<![\\pL\\pM\\pN_])'.preg_quote($word, '/').'(?![\\pL\\pM\\pN_])/ui';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
