<?php

namespace App\Console\Commands;

use App\Models\ManagedServer;
use App\Models\ProxyServer;
use App\Models\ServerDeployment;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedSpeedboxUiDemo extends Command
{
    protected $signature = 'speedbox:demo-data {--reports : Create four historical demo operation reports}';

    protected $description = 'Create local preview records for server, node and connection-plan screens.';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('این دستور فقط در محیط local برای بررسی ظاهر پنل قابل اجراست.');
            return self::FAILURE;
        }

        $tables = ['speedbox_managed_servers', 'v2_server_machine', 'v2_server_group', 'v2_server', 'v2_plan'];
        if ($this->option('reports')) {
            $tables[] = 'speedbox_deployments';
        }
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('ابتدا مهاجرت‌های پروژه را اجرا کنید: php artisan migrate');
                return self::FAILURE;
            }
        }

        $ids = DB::transaction(function () {
            $group = ServerGroup::firstOrCreate(['name' => 'نمایشی — گروه رابط کاربری']);
            $machine = ServerMachine::firstOrCreate(['name' => 'نمایشی — ماشین محلی'], [
                'token' => ServerMachine::generateToken(),
                'notes' => 'speedbox-ui-demo: local display preview',
                'is_active' => false,
            ]);
            $server = ManagedServer::firstOrCreate(['host' => '192.0.2.10', 'ssh_port' => 2222], [
                'name' => 'نمایشی — سرور آزمایشی',
                'machine_id' => $machine->id,
                'ssh_user' => 'root',
                'auth_type' => 'password',
                'password' => 'demo-ui-only',
                'status' => 'draft',
                'operating_system' => 'Ubuntu 24.04',
                'architecture' => 'amd64',
            ]);
            $node = ProxyServer::firstOrCreate(['type' => 'vless', 'code' => 'speedbox-ui-demo'], [
                'name' => 'نمایشی — نود آزمایشی',
                'machine_id' => $machine->id,
                'host' => 'demo-node.invalid',
                'port' => '443',
                'server_port' => 10443,
                'kernel' => 'singbox',
                'rate' => 1,
                'group_ids' => [$group->id],
                'route_ids' => [],
                'tags' => ['نمایشی'],
                'protocol_settings' => ['tls' => 0, 'network' => 'tcp', 'network_settings' => []],
                'show' => false,
                'enabled' => false,
                'sort' => ((int) ProxyServer::max('sort')) + 1,
            ]);
            $planQuery = DB::table('v2_plan')->where('group_id', $group->id)->where('name', 'نمایشی — پلن ۱۰۰ گیگ');
            $planId = $planQuery->value('id');
            if (!$planId) {
                $planId = DB::table('v2_plan')->insertGetId([
                    'name' => 'نمایشی — پلن ۱۰۰ گیگ',
                    'group_id' => $group->id,
                    'transfer_enable' => 100,
                    'speed_limit' => 50,
                    'device_limit' => 2,
                    'show' => false,
                    'renew' => false,
                    'sort' => ((int) DB::table('v2_plan')->max('sort')) + 1,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
            if ($this->option('reports')) {
                $this->createDemoReports($server);
            }
            return [$server->id, $node->id, $planId];
        });

        $this->info('داده‌های نمایشی آماده‌اند؛ صفحه‌های سرورها، نودها و پلن‌های اتصال را باز کنید.');
        $this->table(['سرور', 'نود', 'پلن'], [$ids]);
        if ($this->option('reports')) {
            $this->info('چهار گزارش نمایشی نصب، راه‌اندازی مجدد، بازسازی ناموفق و بررسی وضعیت آماده‌اند؛ دکمه گزارش سرور آزمایشی را بزنید.');
        }
        $this->line('هیچ نصب یا اتصال SSH اجرا نشده است. اجرای دوباره همین نمونه‌ها را تکراری نمی‌کند.');
        return self::SUCCESS;
    }

    private function createDemoReports(ManagedServer $server): void
    {
        // Keep previews older than existing operations, including queued jobs.
        $oldest = $server->deployments()->oldest()->value('created_at');
        $anchor = $oldest ? Carbon::parse($oldest) : now();
        $reports = [
            ['install', 'succeeded', 100, "گزارش نمایشی نصب\nاتصال به سرور برقرار شد.\nسیستم Ubuntu و معماری amd64 شناسایی شد.\nبسته عامل بارگذاری شد.\nنصب با موفقیت پایان یافت."],
            ['restart', 'succeeded', 100, "گزارش نمایشی راه‌اندازی مجدد\nدرخواست راه‌اندازی مجدد سرویس عامل اجرا شد.\nسرویس عامل فعال است."],
            ['repair', 'failed', 55, "گزارش نمایشی بازسازی ناموفق\nاتصال برقرار شد و بسته عامل بارگذاری شد.\nخطای نمایشی: اجرای نصب به دلیل نداشتن دسترسی لازم متوقف شد."],
            ['check', 'succeeded', 100, "گزارش نمایشی بررسی وضعیت\nسرویس عامل فعال است.\nارتباط و وضعیت سرویس بررسی شد."],
        ];
        foreach ($reports as $index => [$action, $status, $progress, $description]) {
            $created = $anchor->copy()->subHours(4 - $index);
            ServerDeployment::firstOrCreate([
                'managed_server_id' => $server->id,
                'log' => "[speedbox-ui-demo:{$action}]\n{$description}",
            ], [
                'action' => $action,
                'kernel' => 'singbox',
                'status' => $status,
                'progress' => $progress,
                'created_at' => $created,
                'updated_at' => $created->copy()->addMinutes(2),
                'started_at' => $created->copy()->addSeconds(5),
                'finished_at' => $created->copy()->addMinutes(2),
            ]);
        }
    }
}
