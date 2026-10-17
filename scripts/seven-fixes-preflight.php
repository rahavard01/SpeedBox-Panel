<?php

// Read-only deployment gate. No credentials or payment identities are printed.
try {
    $root = getenv('SPEEDBOX_PREFLIGHT_ROOT') ?: dirname(__DIR__);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql') throw new RuntimeException('Expected configured MySQL connection.');
    $schema = $db->getSchemaBuilder();
    foreach (['panel_users', 'panel_crypto_topups', 'user_bot_orders', 'panel_announcements', 'panel_announcement_reads'] as $table) {
        if (!$schema->hasTable($table)) throw new RuntimeException('Required table is missing: '.$table);
    }
    $pending = glob($root.'/database/migrations/*.php');
    $migrator = $app->make('migrator');
    $completed = $schema->hasTable('migrations') ? $db->table('migrations')->pluck('migration')->all() : [];
    foreach ($pending as $file) {
        if (basename($file) === '2026_10_16_000001_create_speedbox_crypto_payment_claims.php') continue;
        // Match Laravel's actual migration repository naming, including legacy .php.php files.
        if (!in_array($migrator->getMigrationName($file), $completed, true)) {
            throw new RuntimeException('An earlier migration is pending; review before deployment: '.basename($file));
        }
    }
    $claims = $schema->hasTable('speedbox_crypto_payment_claims');
    if ($claims) {
        foreach (['id', 'network', 'tx_hash', 'owner_type', 'owner_id', 'created_at', 'updated_at'] as $column) {
            if (!$schema->hasColumn('speedbox_crypto_payment_claims', $column)) throw new RuntimeException('Payment claim schema is incomplete.');
        }
        $primary = false;
        foreach ($schema->getIndexes('speedbox_crypto_payment_claims') as $index) {
            if (($index['primary'] ?? false) && ($index['columns'] ?? []) === ['id']) $primary = true;
        }
        if (!$primary) throw new RuntimeException('Payment claim primary key is missing.');
    }
    if (getenv('SPEEDBOX_REQUIRE_CLAIMS') === '1' && !$claims) throw new RuntimeException('Payment claim migration has not completed.');
    echo 'database_driver=mysql'.PHP_EOL.'payment_claims_table='.($claims ? 'ready' : 'pending_additive_migration').PHP_EOL.'SEVEN FIXES PREFLIGHT OK'.PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    // Laravel's console exception renderer alone does not guarantee exit(1) here.
    fwrite(STDERR, 'SEVEN FIXES PREFLIGHT FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
