<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Collection as ModelCollection;

final class VpnOperation extends Model
{
    protected $table = 'speedbox_vpn_operations';

    protected $fillable = ['service_type', 'instance_id', 'action', 'status', 'progress', 'log'];

    public static function record(string $service, int $id, string $action): void
    {
        self::create([
            'service_type' => $service, 'instance_id' => $id, 'action' => $action,
            'status' => 'queued', 'progress' => 0, 'log' => 'عملیات در صف قرار گرفت.',
        ]);
    }

    public static function pending(string $service, int $id): bool
    {
        return self::forInstance($service, $id)->whereIn('status', ['queued', 'running'])->exists();
    }

    public static function start(string $service, int $id): ?self
    {
        $operation = self::forInstance($service, $id)->where('status', 'queued')->latest('id')->first();
        $operation?->recordProgress(5, 'اجرای عملیات شروع شد.', 'running');

        return $operation;
    }

    public static function failUnfinished(string $service, int $id, string $action, string $message): void
    {
        $operation = self::forInstance($service, $id)->latest('id')->first();
        if (in_array($operation?->status, ['queued', 'running'], true) && ($action === '*' || $operation->action === $action)) {
            $operation->fail($message);
        }
    }

    public function fail(string $message): void
    {
        $this->recordProgress($this->progress, mb_substr($message ?: 'عملیات ناموفق بود.', 0, 1000), 'failed');
    }

    public function recordProgress(int $progress, string $message, string $status = 'running'): void
    {
        $this->update([
            'progress' => $progress,
            'log' => $this->log."\n".now()->format('H:i:s').'  '.$message,
            'status' => $status,
        ]);
    }

    public static function attachLatest(string $service, ModelCollection $instances): Collection
    {
        $latestIds = self::where('service_type', $service)->whereIn('instance_id', $instances->modelKeys())
            ->groupBy('instance_id')->selectRaw('MAX(id)');
        $operations = self::whereIn('id', $latestIds)->get(['id', 'instance_id', 'action', 'status', 'progress', 'updated_at'])->keyBy('instance_id');

        return $instances->each(fn ($instance) => $instance->setAttribute('operation', $operations->get($instance->id)));
    }

    public static function history(string $service, int $id, ?Model $instance, bool $latest): Collection
    {
        $operations = self::forInstance($service, $id)->latest('id')->limit($latest ? 1 : 10)->get();
        abort_if(!$instance && $operations->isEmpty(), 404);
        if ($instance?->last_error && ($operations->isEmpty() ||
            ($operations->first()->status === 'succeeded' && !str_contains($operations->first()->log, $instance->last_error)))) {
            $operations->prepend([
                'id' => 0, 'action' => 'previous', 'status' => 'failed', 'progress' => 0,
                'log' => $instance->last_error, 'created_at' => $instance->updated_at,
            ]);
        }

        return $operations;
    }

    private static function forInstance(string $service, int $id)
    {
        return self::where('service_type', $service)->where('instance_id', $id);
    }
}
