<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'blocked_users' => User::query()->whereNotNull('block_action_id')->count(),
            'hidden_advertisements' => Advertisement::query()->whereNotNull('hide_action_id')->count(),
            'open_reports' => Report::query()->where('status', ReportStatus::OPEN)->count(),
        ]);
    }
}
