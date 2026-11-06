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
        foreach (['banned_words.json', 'banned_words_en.json', 'banned_words_es.json'] as $file) {
            $words = json_decode(File::get(database_path('data/'.$file)), true, 512, JSON_THROW_ON_ERROR);
            foreach ($words as $word) {
                BannedWord::firstOrCreate(['word' => mb_strtolower(trim($word['word']))]);
            }
        }
    }
}
