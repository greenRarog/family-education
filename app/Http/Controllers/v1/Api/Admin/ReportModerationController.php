<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['all', ...array_column(ReportStatus::cases(), 'value')])],
            'target_type' => ['sometimes', Rule::in(['all', ...array_column(ReportTargetType::cases(), 'value')])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = Report::query()->orderByDesc('id');
        if (($data['status'] ?? 'open') !== 'all') {
            $query->where('status', $data['status'] ?? 'open');
        }
        if (($data['target_type'] ?? 'all') !== 'all') {
            $query->where('target_type', $data['target_type']);
        }

        return response()->json($query->paginate(20, ['id', 'reporter_id', 'target_type', 'target_id', 'reason', 'status', 'created_at', 'resolved_at']));
    }

    public function show(Report $report): JsonResponse
    {
        return response()->json(['report' => $this->card($report)]);
    }

    public function history(Request $request, Report $report): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $linked = $report->resolutionActions()->pluck('admin_actions.id');

        return response()->json(AdminAction::query()->where(function ($query) use ($report, $linked): void {
            $query->where('report_id', $report->id)->orWhereIn('id', $linked);
        })->orderByDesc('id')->paginate(20));
    }

    public function resolve(Request $request, Report $report, ReportResolutionService $service): JsonResponse
    {
        return response()->json(['report' => $this->card($service->resolve($request->user(), $report, $request->all()))]);
    }

    /** @return array<string, mixed> */
    private function card(Report $report): array
    {
        $ad = $report->target_type === 'advertisement' ? Advertisement::query()->find($report->target_id) : null;
        $userId = $report->target_type === 'user' ? $report->target_id : $ad?->user_id;
        $user = $userId === null ? null : User::query()->find($userId);

        return array_merge($report->attributesToArray(), [
            'reporter' => $report->reporter->only(['id', 'name', 'email', 'user_type']),
            'target_exists' => $report->target_type === 'advertisement' ? $ad !== null : $user !== null,
            'target_user_id' => $user?->id,
            'active_action_ids' => array_values(array_filter([$user?->block_action_id, $ad?->hide_action_id])),
            'resolution_action_ids' => $report->resolutionActions()->pluck('admin_actions.id')->all(),
        ]);
    }
}
