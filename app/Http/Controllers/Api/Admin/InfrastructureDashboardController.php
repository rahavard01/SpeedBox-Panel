<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InfrastructureDashboardController extends Controller
{
    public function trafficRank(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:node,user'],
            'range' => ['nullable', 'in:today,last7days,last30days,custom'],
            'start_date' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));
        $range = $data['range'] ?? 'today';
        $start = match ($range) {
            'custom' => CarbonImmutable::parse($data['start_date'], config('app.timezone'))->startOfDay(),
            'last7days' => $today->subDays(6),
            'last30days' => $today->subDays(29),
            default => $today,
        };
        $end = $range === 'custom'
            ? CarbonImmutable::parse($data['end_date'], config('app.timezone'))->startOfDay()->addDay()
            : $today->addDay();
        abort_if($end->timestamp - $start->timestamp > 366 * 86400, 422, 'بازهٔ تاریخ حداکثر یک سال است.');
        $previousStart = $start->subSeconds($end->timestamp - $start->timestamp);
        $node = $data['type'] === 'node';
        $table = $node ? 'v2_stat_server' : 'v2_stat_user';
        $idColumn = $node ? 'server_id' : 'user_id';
        $current = DB::table($table)->where('record_type', 'd')->where('record_at', '>=', $start->timestamp)
            ->where('record_at', '<', $end->timestamp)->selectRaw("{$idColumn} as id, SUM(u) as upload, SUM(d) as download, SUM(u + d) as value")
            ->groupBy($idColumn)->orderByDesc('value')->orderBy($idColumn)->limit(10)->get();
        $previous = DB::table($table)->where('record_type', 'd')->where('record_at', '>=', $previousStart->timestamp)
            ->where('record_at', '<', $start->timestamp)->whereIn($idColumn, $current->pluck('id'))
            ->selectRaw("{$idColumn} as id, SUM(u + d) as value")->groupBy($idColumn)->get()->keyBy('id');
        $names = DB::table($node ? 'v2_server' : 'v2_user')->whereIn('id', $current->pluck('id'))->pluck($node ? 'name' : 'email', 'id');
        $rows = $current->map(function ($row) use ($previous, $names, $node) {
            $old = (int) ($previous[$row->id]->value ?? 0);
            $name = $names[$row->id] ?? ($node ? 'نود '.$row->id : 'کاربر '.$row->id);
            if (!$node) {
                // Match the existing Panel's Ct() account label, rather than showing an email.
                $name = preg_replace('/\.com/', '', preg_replace('/@/', '-', $name, 1), 1);
            }
            return ['id' => (string) $row->id, 'name' => $name, 'value' => (int) $row->value,
                'upload' => (int) $row->upload, 'download' => (int) $row->download, 'previousValue' => $old,
                'change' => $old > 0 ? round(((int) $row->value - $old) / $old * 100, 1) : 0];
        });
        return response()->json(['data' => $rows, 'period' => [
            'start' => $start->toDateString(), 'end' => $end->subDay()->toDateString(),
            'previous_start' => $previousStart->toDateString(), 'previous_end' => $start->subDay()->toDateString(),
            'timezone' => config('app.timezone'),
        ]]);
    }

    public function queue(QueueMonitor $monitor): JsonResponse
    {
        return response()->json(['data' => $monitor->snapshot()]);
    }

    public function failedJobs(QueueMonitor $monitor): JsonResponse
    {
        return response()->json(['data' => $monitor->failedJobs()]);
    }
}
