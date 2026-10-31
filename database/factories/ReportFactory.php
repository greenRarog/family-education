<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportContextType;
use App\Enums\ReportReason;
use App\Enums\ReportTargetType;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'target_type' => ReportTargetType::ADVERTISEMENT->value,
            'target_id' => Advertisement::factory(),
            'context_type' => ReportContextType::ADVERTISEMENT,
            'context_id' => fn (array $attributes) => $attributes['target_id'],
            'reason' => ReportReason::SPAM,
            'comment' => null,
            'snapshot' => fn (array $attributes) => [
                'description' => Advertisement::query()->findOrFail($attributes['target_id'])->description,
            ],
        ];
    }
}
