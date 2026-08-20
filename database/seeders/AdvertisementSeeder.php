<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementStudyFormat;
use App\Enums\AdvertisementType;
use App\Models\Advertisement;
use App\Models\Child;
use App\Models\City;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class AdvertisementSeeder extends Seeder
{
    public function run(): void
    {
        $moscow = City::where('name', 'Москва')->firstOrFail();
        $spb = City::where('name', 'Санкт-Петербург')->firstOrFail();
        $krasnodar = City::where('name', 'Краснодар')->firstOrFail();

        $khamovniki = District::where('city_id', $moscow->id)
            ->where('name', 'Хамовники')
            ->firstOrFail();

        $centralSpb = District::where('city_id', $spb->id)
            ->where('name', 'Центральный')
            ->firstOrFail();

        $centralKrasnodar = District::where('city_id', $krasnodar->id)
            ->where('name', 'Центральный')
            ->firstOrFail();

        $kropotkinskaya = MetroStation::where('city_id', $moscow->id)
            ->where('name', 'Кропоткинская')
            ->firstOrFail();

        $families = Family::query()
            ->whereIn('user_id', function ($query) {
                $query->select('id')
                    ->from('users')
                    ->whereIn('email', [
                        'alexey@example.com',
                        'maria@example.com',
                        'dmitry@example.com',
                    ]);
            })
            ->get()
            ->keyBy('user_id');

        $alexey = $families->get(
            $families->firstWhere('user_id', function () {
                return false;
            })?->user_id
        );

        $moscowFamily = Family::query()
            ->where('city_id', $moscow->id)
            ->firstOrFail();

        $spbFamily = Family::query()
            ->where('city_id', $spb->id)
            ->firstOrFail();

        $krasnodarFamily = Family::query()
            ->where('city_id', $krasnodar->id)
            ->firstOrFail();

        $moscowChild = Child::where('family_id', $moscowFamily->id)->firstOrFail();
        $spbChild = Child::where('family_id', $spbFamily->id)->firstOrFail();
        $krasnodarChild = Child::where('family_id', $krasnodarFamily->id)->firstOrFail();

        $math = Subject::where('name', 'Математика')->firstOrFail();
        $english = Subject::where('name', 'Английский язык')->firstOrFail();
        $russian = Subject::where('name', 'Русский язык')->firstOrFail();

        $publishedAt = now()->subDays(1);

        $advertisements = [
            [
                'user_id' => $moscowFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_FAMILY,
                'format' => null,
                'city_id' => $moscow->id,
                'district_id' => $khamovniki->id,
                'metro_station_id' => $kropotkinskaya->id,
                'participant_age_from' => 7,
                'participant_age_to' => 10,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация поиска участников в учебную группу. Подходит для просмотра карточки объявления и показывает, как семья может найти других участников для регулярных совместных занятий.',
                'children' => [$moscowChild->id],
                'subjects' => [],
            ],
            [
                'user_id' => $spbFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_FAMILY,
                'format' => null,
                'city_id' => $spb->id,
                'district_id' => $centralSpb->id,
                'metro_station_id' => null,
                'participant_age_from' => 10,
                'participant_age_to' => 13,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация фильтра по городу и возрасту. Это объявление находится в Санкт-Петербурге и рассчитано на участников от 10 до 13 лет.',
                'children' => [$spbChild->id],
                'subjects' => [],
            ],
            [
                'user_id' => $krasnodarFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_FAMILY,
                'format' => null,
                'city_id' => $krasnodar->id,
                'district_id' => $centralKrasnodar->id,
                'metro_station_id' => null,
                'participant_age_from' => 6,
                'participant_age_to' => 8,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация объявления с другим городом и возрастным диапазоном. Используйте его, чтобы проверить совместную работу фильтров города и возраста.',
                'children' => [$krasnodarChild->id],
                'subjects' => [],
            ],
            [
                'user_id' => $moscowFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_TEACHER,
                'format' => AdvertisementStudyFormat::ONLINE,
                'city_id' => $moscow->id,
                'district_id' => $khamovniki->id,
                'metro_station_id' => $kropotkinskaya->id,
                'participant_age_from' => 8,
                'participant_age_to' => 11,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация поиска педагога в онлайн-формате. Здесь можно увидеть предметы, формат обучения и проверить фильтр по типу объявления.',
                'children' => [$moscowChild->id],
                'subjects' => [$math->id],
            ],
            [
                'user_id' => $spbFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_TEACHER,
                'format' => AdvertisementStudyFormat::OFFLINE,
                'city_id' => $spb->id,
                'district_id' => $centralSpb->id,
                'metro_station_id' => null,
                'participant_age_from' => 9,
                'participant_age_to' => 12,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация поиска педагога в офлайн-формате. Объявление предназначено для проверки фильтров по типу, городу, возрасту и предмету.',
                'children' => [$spbChild->id],
                'subjects' => [$english->id],
            ],
            [
                'user_id' => $krasnodarFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_TEACHER,
                'format' => AdvertisementStudyFormat::HYBRID,
                'city_id' => $krasnodar->id,
                'district_id' => $centralKrasnodar->id,
                'metro_station_id' => null,
                'participant_age_from' => 12,
                'participant_age_to' => 15,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация гибридного формата обучения. Используется для знакомства с дополнительными параметрами объявления и проверки фильтра по предмету.',
                'children' => [$krasnodarChild->id],
                'subjects' => [$russian->id, $math->id],
            ],
            [
                'user_id' => $moscowFamily->user_id,
                'type' => AdvertisementType::FAMILY_TO_TEACHER,
                'format' => AdvertisementStudyFormat::ONLINE,
                'city_id' => $moscow->id,
                'district_id' => $khamovniki->id,
                'metro_station_id' => $kropotkinskaya->id,
                'participant_age_from' => 14,
                'participant_age_to' => 17,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация второго объявления с тем же городом и типом, но другим возрастом и предметами. Нужна для проверки того, что фильтры действительно отбирают подходящие объявления.',
                'children' => [$moscowChild->id],
                'subjects' => [$english->id, $russian->id],
            ],
            [
                'user_id' => $spbFamily->user_id,
                'type' => AdvertisementType::TEACHER_TO_SERVICE,
                'format' => AdvertisementStudyFormat::ONLINE,
                'city_id' => $spb->id,
                'district_id' => $centralSpb->id,
                'metro_station_id' => null,
                'participant_age_from' => 7,
                'participant_age_to' => 17,
                'description' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация третьего типа объявления — предложение услуг от педагога. Этот тип добавлен для демонстрации полного набора доступных типов объявлений MVP.',
                'children' => [],
                'subjects' => [$math->id],
            ],
        ];

        foreach ($advertisements as $data) {
            $children = $data['children'];
            $subjects = $data['subjects'];

            unset($data['children'], $data['subjects']);

            $advertisement = Advertisement::query()->create([
                ...$data,
                'status' => AdvertisementStatus::PUBLISHED,
                'published_at' => $publishedAt,
            ]);

            if ($children !== []) {
                $advertisement->children()->attach($children);
            }

            if ($subjects !== []) {
                $advertisement->subjects()->attach($subjects);
            }
        }
    }
}
