<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BannedWord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use JsonException;

class BannedWordSeeder extends Seeder
{
    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $path = database_path('data/banned_words.json');

        $words = json_decode(
            File::get($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($words as $word) {
            BannedWord::firstOrCreate([
                'word' => mb_strtolower(trim($word['word'])),
            ]);
        }
    }
}
