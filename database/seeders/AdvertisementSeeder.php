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
use App\Models\User;
use Illuminate\Database\Seeder;

class AdvertisementSeeder extends Seeder
{
    public function run(): void
    {
        $moscow = City::whereRelation('country', 'code', 'RU')->where('name', 'Москва')->firstOrFail();
        $spb = City::whereRelation('country', 'code', 'RU')->where('name', 'Санкт-Петербург')->firstOrFail();
        $krasnodar = City::whereRelation('country', 'code', 'RU')->where('name', 'Краснодар')->firstOrFail();

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

        $demoUsers = User::query()
            ->whereIn('email', [
                'alexey@example.com',
                'maria@example.com',
                'dmitry@example.com',
            ])
            ->get()
            ->keyBy('email');

        $moscowFamily = Family::query()
            ->where('user_id', $demoUsers['alexey@example.com']->id)
            ->firstOrFail();

        $spbFamily = Family::query()
            ->where('user_id', $demoUsers['maria@example.com']->id)
            ->firstOrFail();

        $krasnodarFamily = Family::query()
            ->where('user_id', $demoUsers['dmitry@example.com']->id)
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация поиска участников в учебную группу. Подходит для просмотра карточки объявления и показывает, как семья может найти других участников для регулярных совместных занятий.',
                    'en' => 'DEMO ADVERTISEMENT! A sample search for families to join a learning group. Open the advertisement to see how a family can find others for regular group lessons.',
                    'es' => '¡ANUNCIO DE PRUEBA! Ejemplo de búsqueda de familias para formar un grupo de aprendizaje. Abre el anuncio para ver cómo una familia puede encontrar a otras para organizar clases en grupo.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация фильтра по городу и возрасту. Это объявление находится в Санкт-Петербурге и рассчитано на участников от 10 до 13 лет.',
                    'en' => 'DEMO ADVERTISEMENT! Try the city and age filters. This learning group is in Saint Petersburg and is intended for children aged 10 to 13.',
                    'es' => '¡ANUNCIO DE PRUEBA! Prueba los filtros de ciudad y edad. Este grupo de aprendizaje está en San Petersburgo y está pensado para niños de 10 a 13 años.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация объявления с другим городом и возрастным диапазоном. Используйте его, чтобы проверить совместную работу фильтров города и возраста.',
                    'en' => 'DEMO ADVERTISEMENT! A learning group in Krasnodar for children aged 6 to 8. Use this sample to try the city and age filters together.',
                    'es' => '¡ANUNCIO DE PRUEBA! Un grupo de aprendizaje en Krasnodar para niños de 6 a 8 años. Usa este ejemplo para probar los filtros de ciudad y edad a la vez.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация поиска педагога в онлайн-формате. Здесь можно увидеть предметы, формат обучения и проверить фильтр по типу объявления.',
                    'en' => 'DEMO ADVERTISEMENT! A family is looking for an online mathematics teacher. Explore the subjects and lesson format, and try filtering by advertisement type.',
                    'es' => '¡ANUNCIO DE PRUEBA! Una familia busca un docente de matemáticas para clases en línea. Consulta las asignaturas y el formato de las clases, y prueba el filtro de tipo de anuncio.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация поиска педагога в офлайн-формате. Объявление предназначено для проверки фильтров по типу, городу, возрасту и предмету.',
                    'en' => 'DEMO ADVERTISEMENT! A family is looking for an English teacher for in-person lessons in Saint Petersburg. Try combining the type, city, age and subject filters.',
                    'es' => '¡ANUNCIO DE PRUEBA! Una familia busca un docente de inglés para clases presenciales en San Petersburgo. Prueba a combinar los filtros de tipo, ciudad, edad y asignatura.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация гибридного формата обучения. Используется для знакомства с дополнительными параметрами объявления и проверки фильтра по предмету.',
                    'en' => 'DEMO ADVERTISEMENT! A family is looking for Russian language and mathematics lessons in a hybrid format: online and in person. Explore the additional details and try the subject filter.',
                    'es' => '¡ANUNCIO DE PRUEBA! Una familia busca clases de lengua rusa y matemáticas en formato mixto: en línea y presenciales. Explora los detalles adicionales y prueba el filtro de asignatura.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация второго объявления с тем же городом и типом, но другим возрастом и предметами. Нужна для проверки того, что фильтры действительно отбирают подходящие объявления.',
                    'en' => 'DEMO ADVERTISEMENT! Another family request in Moscow, this time for online English and Russian language lessons for ages 14 to 17. Compare it with the other samples to see how age and subject filters narrow the results.',
                    'es' => '¡ANUNCIO DE PRUEBA! Otra solicitud de una familia en Moscú, esta vez para clases en línea de inglés y lengua rusa para edades de 14 a 17 años. Compárala con los demás ejemplos para ver cómo los filtros de edad y asignatura acotan los resultados.',
                ],
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
                'descriptions' => [
                    'ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ! Демонстрация третьего типа объявления — предложение услуг от педагога. Этот тип добавлен для демонстрации полного набора доступных типов объявлений MVP.',
                    'en' => 'DEMO ADVERTISEMENT! A sample offer of online mathematics lessons from a teacher for ages 7 to 17. Explore this third advertisement type to see all the types available in the service.',
                    'es' => '¡ANUNCIO DE PRUEBA! Ejemplo de una oferta de clases de matemáticas en línea de un docente para edades de 7 a 17 años. Explora este tercer tipo de anuncio para conocer todos los tipos disponibles en el servicio.',
                ],
                'children' => [],
                'subjects' => [$math->id],
            ],
        ];

        foreach ($advertisements as $data) {
            $children = $data['children'];
            $subjects = $data['subjects'];
            $descriptions = $data['descriptions'];

            unset($data['children'], $data['subjects'], $data['descriptions']);

            foreach ($descriptions as $language => $description) {
                $advertisement = Advertisement::query()->updateOrCreate(
                    [
                        'user_id' => $data['user_id'],
                        'description' => $description,
                    ],
                    [
                        ...$data,
                        'language' => $language,
                        'status' => AdvertisementStatus::PUBLISHED,
                        'published_at' => $publishedAt,
                    ],
                );

                $advertisement->children()->sync($children);
                $advertisement->subjects()->sync($subjects);
            }
        }
    }
}
