<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Advertisement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Whole connected cases only. Ordinary model/query deletion remains forbidden. */
class ModerationRetentionService
{
    public function purge(?string $connection = null): int
    {
        $db = DB::connection($connection);
        $removed = 0;
        foreach ($this->cases($db) as $case) {
            $removed += $db->transaction(function () use ($db, $case): int {
                $reports = $db->table('reports')->whereIn('id', $case['reports'])->orderBy('id')->lockForUpdate()->get();
                $actions = $db->table('admin_actions')->whereIn('id', $case['actions'])->orderBy('id')->get();
                $adIds = $actions->where('target_type', 'advertisement')->pluck('target_id')
                    ->merge($reports->where('target_type', 'advertisement')->pluck('target_id'))->unique()->all();
                $userIds = $actions->where('target_type', 'user')->pluck('target_id')
                    ->merge($reports->where('target_type', 'user')->pluck('target_id'))
                    ->merge($db->table('advertisements')->whereIn('id', $adIds)->pluck('user_id'))->unique()->all();
                User::on($db->getName())->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get();
                Advertisement::on($db->getName())->whereIn('id', $adIds)->orderBy('id')->lockForUpdate()->get();
                $db->table('admin_actions')->whereIn('id', $case['actions'])->orderBy('id')->lockForUpdate()->get();
                // A linked decision may have committed while locks were acquired.
                if (! in_array($case, $this->cases($db), true)) {
                    return 0;
                }
                if (! $this->expired($db, $case)) {
                    return 0;
                }
                foreach ($case['actions'] as $id) {
                    $db->table('moderation_purge_actions')->insert(['admin_action_id' => $id]);
                }
                $db->table('users')->whereNotNull('account_deleted_at')->whereIn('block_action_id', $case['actions'])->update(['block_action_id' => null]);
                $db->table('advertisements')->whereNotNull('account_deleted_at')->whereIn('hide_action_id', $case['actions'])->update(['hide_action_id' => null]);
                $db->table('report_resolution_actions')->whereIn('report_id', $case['reports'])->delete();
                // Keep notification generations monotonic when their audit rows expire.
                foreach ($actions->where('action', 'user_blocked')->groupBy('target_id') as $userId => $blocks) {
                    $db->table('users')->where('id', $userId)->increment('purged_block_count', $blocks->count());
                }
                // Child decisions first: preserve immediate SQLite RESTRICT semantics.
                $pending = $case['actions'];
                while ($pending !== []) {
                    $progress = false;
                    foreach ($pending as $key => $id) {
                        if (! $db->table('admin_actions')->where('related_action_id', $id)->exists()) {
                            $db->table('admin_actions')->where('id', $id)->delete();
                            unset($pending[$key]);
                            $progress = true;
                        }
                    }
                    if (! $progress) {
                        throw new LogicException('Cyclic moderation history cannot be purged.');
                    }
                }
                $db->table('reports')->whereIn('id', $case['reports'])->delete();

                return count($case['reports']) + count($case['actions']);
            });
        }

        return $removed;
    }

    /** @return array{reports: list<int>, actions: list<int>} */
    public function expiredIds(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $expired = ['reports' => [], 'actions' => []];
        foreach ($this->cases($db) as $case) {
            if ($this->expired($db, $case)) {
                array_push($expired['reports'], ...$case['reports']);
                array_push($expired['actions'], ...$case['actions']);
            }
        }

        return $expired;
    }

    /** @param array{reports: list<int>, actions: list<int>} $case */
    private function expired(Connection $db, array $case): bool
    {
        $reports = $db->table('reports')->whereIn('id', $case['reports'])->get(['status', 'resolved_at']);
        $actions = $db->table('admin_actions')->whereIn('id', $case['actions'])->get(['id', 'target_id', 'target_type', 'action', 'occurred_at']);
        $users = User::on($db->getName())->whereIn('id', $actions->where('target_type', 'user')->pluck('target_id'))->get()->keyBy('id');
        $ads = Advertisement::on($db->getName())->whereIn('id', $actions->where('target_type', 'advertisement')->pluck('target_id'))->get()->keyBy('id');
        $last = null;
        foreach ($reports as $report) {
            if ($report->status === 'open' || $report->resolved_at === null) {
                return false;
            }
            $at = CarbonImmutable::parse($report->resolved_at, 'UTC');
            $last = $last === null || $at->gt($last) ? $at : $last;
        }
        foreach ($actions as $action) {
            $at = CarbonImmutable::parse($action->occurred_at, 'UTC');
            if ($action->action === 'user_blocked') {
                $target = $users->get($action->target_id);
                if ($target === null) {
                    return false; // No evidence of the date the measure stopped applying.
                }
                if ($target->block_action_id === (int) $action->id) {
                    if (! $target->isAccountDeleted()) {
                        return false;
                    }
                    $at = CarbonImmutable::instance($target->account_deleted_at);
                }
            }
            if ($action->action === 'advertisement_hidden') {
                $target = $ads->get($action->target_id);
                if ($target === null) {
                    return false;
                }
                if ($target->hide_action_id === (int) $action->id) {
                    if (! $target->isAccountDeleted()) {
                        return false;
                    }
                    $at = CarbonImmutable::instance($target->account_deleted_at);
                }
            }
            $last = $last === null || $at->gt($last) ? $at : $last;
        }

        return $last !== null && $last->addMonthsNoOverflow(12)->lte(now());
    }

    /** @return list<array{reports: list<int>, actions: list<int>}> */
    private function cases(Connection $db): array
    {
        $graph = [];
        $link = static function (string $a, string $b) use (&$graph): void {
            $graph[$a][] = $b;
            $graph[$b][] = $a;
        };
        foreach ($db->table('reports')->pluck('id') as $id) {
            $graph['r'.$id] = [];
        }
        foreach ($db->table('admin_actions')->get(['id', 'report_id', 'related_action_id']) as $action) {
            $key = 'a'.$action->id;
            $graph[$key] ??= [];
            if ($action->report_id !== null) {
                $link($key, 'r'.$action->report_id);
            }
            if ($action->related_action_id !== null) {
                $link($key, 'a'.$action->related_action_id);
            }
        }
        foreach ($db->table('report_resolution_actions')->get() as $pivot) {
            $link('r'.$pivot->report_id, 'a'.$pivot->admin_action_id);
        }
        $cases = [];
        $seen = [];
        foreach (array_keys($graph) as $root) {
            if (isset($seen[$root])) {
                continue;
            }
            $case = ['reports' => [], 'actions' => []];
            $stack = [$root];
            while ($stack !== []) {
                $key = array_pop($stack);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $case[str_starts_with($key, 'r') ? 'reports' : 'actions'][] = (int) substr($key, 1);
                array_push($stack, ...$graph[$key]);
            }
            sort($case['reports']);
            sort($case['actions']);
            $cases[] = $case;
        }

        return $cases;
    }
}
