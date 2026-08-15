<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserType;
use App\Models\Child;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
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
     *     surname: string,
     *     city_id: int|string,
     *     district_id?: int|string|null,
     *     metro_station_id?: int|string|null,
     *     children: array<int, array{
     *         name: string,
     *         birth_date: string,
     *         sex: string
     *     }>
     * } $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
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

            'children' => [
                'required',
                'array',
                'min:1',
            ],

            'children.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'children.*.birth_date' => [
                'required',
                'date',
            ],

            'children.*.sex' => [
                'required',
                'string',
                'in:male,female',
            ],
        ])->validate();

        if (! $this->turnstile->verify($input['cf-turnstile-response'])) {
            Validator::make([], [])->after(function ($validator) {
                $validator->errors()->add(
                    'cf-turnstile-response',
                    'Проверка безопасности не пройдена.'
                );
            })->validate();
        }

        $this->validateFamilyLocation($input);

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'user_type' => UserType::FAMILY,
            ]);

            $family = Family::create([
                'user_id' => $user->id,
                'surname' => $input['surname'],
                'city_id' => $input['city_id'],
                'district_id' => $input['district_id'] ?? null,
                'metro_station_id' => $input['metro_station_id'] ?? null,
            ]);

            foreach ($input['children'] as $child) {
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
