<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\SupportedLanguage;
use App\Enums\UserType;
use App\Models\Child;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private readonly TurnstileService $turnstile,
    ) {}

    use PasswordValidationRules;

    /**
     * @param array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     password_confirmation: string,
     *     cf-turnstile-response: string,
     *     user_type?: string,
     *     surname: string,
     *     city_id: int|string,
     *     district_id?: int|string|null,
     *     metro_station_id?: int|string|null,
     *     children: array<int, array{
     *         name: string,
     *         birth_date: string,
     *         sex: string
     *     }>,
     *     subject_ids?: array<int, int|string>,
     *     about?: string|null,
     *     education?: string|null,
     * } $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $userType = $input['user_type'] ?? UserType::FAMILY->value;
        $isTeacher = $userType === UserType::TEACHER->value;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),

            'cf-turnstile-response' => [
                'required',
                'string',
            ],
            'user_type' => [
                'nullable',
                Rule::in([UserType::FAMILY->value, UserType::TEACHER->value]),
            ],
            'language' => ['nullable', Rule::enum(SupportedLanguage::class)],
            'surname' => ['required', 'string', 'max:255'],

            'city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id'),
            ],

            'district_id' => [
                'nullable',
                'integer',
                Rule::exists('districts', 'id'),
            ],

            'metro_station_id' => [
                'nullable',
                'integer',
                Rule::exists('metro_stations', 'id'),
            ],

        ];

        if ($isTeacher) {
            $rules += [
                'subject_ids' => ['required', 'array', 'min:1'],
                'subject_ids.*' => ['required', 'integer', 'distinct', Rule::exists('subjects', 'id')],
                'about' => ['nullable', 'string', 'max:3000'],
                'education' => ['nullable', 'string', 'max:3000'],
            ];
        } else {
            $rules += [
                'children' => ['required', 'array', 'min:1'],
                'children.*.name' => ['required', 'string', 'max:255'],
                'children.*.birth_date' => ['required', 'date', 'before:tomorrow'],
                'children.*.sex' => ['required', 'string', 'in:male,female'],
            ];
        }

        $data = Validator::make($input, $rules, [
            'children.*.birth_date.before' => 'Дата рождения не может быть в будущем.',
        ])->validate();

        if (! $this->turnstile->verify($input['cf-turnstile-response'])) {
            Validator::make([], [])->after(function ($validator) {
                $validator->errors()->add(
                    'cf-turnstile-response',
                    'Проверка безопасности не пройдена.'
                );
            })->validate();
        }

        $this->validateFamilyLocation($data);

        return DB::transaction(function () use ($data, $isTeacher) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'user_type' => $isTeacher ? UserType::TEACHER : UserType::FAMILY,
                'locale' => $data['language'] ?? SupportedLanguage::RUSSIAN->value,
            ]);

            if ($isTeacher) {
                /** @var TeacherProfile $profile */
                $profile = $user->teacherProfile()->create([
                    'surname' => $data['surname'],
                    'city_id' => $data['city_id'],
                    'district_id' => $data['district_id'] ?? null,
                    'metro_station_id' => $data['metro_station_id'] ?? null,
                    'about' => $data['about'] ?? null,
                    'education' => $data['education'] ?? null,
                    'public_profile_requested' => false,
                    'public_profile_status' => 'private',
                ]);

                $profile->subjects()->sync($data['subject_ids']);

                return $user;
            }

            $family = Family::create([
                'user_id' => $user->id,
                'surname' => $data['surname'],
                'city_id' => $data['city_id'],
                'district_id' => $data['district_id'] ?? null,
                'metro_station_id' => $data['metro_station_id'] ?? null,
            ]);

            foreach ($data['children'] as $child) {
                Child::create([
                    'family_id' => $family->id,
                    'name' => $child['name'],
                    'birth_date' => $child['birth_date'],
                    'sex' => $child['sex'],
                ]);
            }

            return $user;
        });
    }

    /**
     * @throws ValidationException
     */
    private function validateFamilyLocation(array $input): void
    {
        if (! empty($input['district_id'])) {
            $districtBelongsToCity = District::query()
                ->whereKey($input['district_id'])
                ->where('city_id', $input['city_id'])
                ->exists();

            if (! $districtBelongsToCity) {
                Validator::make([], [])->after(function ($validator) {
                    $validator->errors()->add(
                        'district_id',
                        'Выбранный район не относится к выбранному городу.'
                    );
                })->validate();
            }
        }

        if (! empty($input['metro_station_id'])) {
            $metroBelongsToCity = MetroStation::query()
                ->whereKey($input['metro_station_id'])
                ->where('city_id', $input['city_id'])
                ->exists();

            if (! $metroBelongsToCity) {
                Validator::make([], [])->after(function ($validator) {
                    $validator->errors()->add(
                        'metro_station_id',
                        'Выбранная станция метро не относится к выбранному городу.'
                    );
                })->validate();
            }
        }
    }
}
