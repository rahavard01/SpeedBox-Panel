<?php

// Deployment helper. Uses the application's configured connection; never prints credentials.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'inspect') {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        if (!preg_match('/^[A-Za-z0-9.:-]+$/D', $host)) throw new RuntimeException('Invalid health host.');
        echo ((bool) config('speedbox.octane.enabled') ? '1' : '0').' '.(int) config('speedbox.octane.port', 7010).' '.$host.PHP_EOL;
    } elseif ($mode === 'queue-idle') {
        $connection = config('queue.connections.database.connection') ?: config('database.default');
        $busy = Illuminate\Support\Facades\DB::connection($connection)
            ->table(config('queue.connections.database.table', 'jobs'))->whereNotNull('reserved_at')->exists();
        exit($busy ? 3 : 0);
    } elseif ($mode === 'backup') {
        $destination = $argv[2] ?? '';
        if ($destination === '' || !is_dir(dirname($destination)) || file_exists($destination)) {
            throw new RuntimeException('A new backup file in an existing private directory is required.');
        }
        $connection = Illuminate\Support\Facades\DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($destination));
        } elseif (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $config = $connection->getConfig();
            $binary = getenv('MYSQLDUMP_BIN') ?: '/www/server/mysql/bin/mysqldump';
            if (!is_executable($binary)) $binary = (new Symfony\Component\Process\ExecutableFinder)->find('mysqldump');
            if (!$binary) throw new RuntimeException('mysqldump was not found; no update was applied.');
            $options = tempnam(dirname($destination), 'mysql-options-');
            if ($options === false) throw new RuntimeException('Cannot create private dump options.');
            chmod($options, 0600);
            $quote = static fn ($v) => '"'.strtr((string) $v, ['\\'=>'\\\\', '"'=>'\\"', "\n"=>'\\n', "\r"=>'\\r']).'"';
            $client = "[client]\nuser=".$quote($config['username'])."\npassword=".$quote($config['password'])."\n";
            $client .= !empty($config['unix_socket']) ? 'socket='.$quote($config['unix_socket'])."\n"
                : 'host='.$quote($config['host'])."\nport=".(int) $config['port']."\n";
            if (defined('PDO::MYSQL_ATTR_SSL_CA') && !empty($config['options'][PDO::MYSQL_ATTR_SSL_CA])) {
                $client .= 'ssl-ca='.$quote($config['options'][PDO::MYSQL_ATTR_SSL_CA])."\n";
            }
            try {
                if (file_put_contents($options, $client) === false) throw new RuntimeException('Cannot write dump options.');
                $process = new Symfony\Component\Process\Process([$binary, '--defaults-extra-file='.$options,
                    '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces',
                    '--result-file='.$destination, '--databases', (string) $config['database']]);
                $process->setTimeout(900);
                $process->run();
                // Do not echo the command, defaults file or credential-bearing process errors.
                if (!$process->isSuccessful()) throw new RuntimeException('Database dump failed (exit '.$process->getExitCode().').');
            } finally {
                unlink($options);
            }
        } else {
            throw new RuntimeException('This updater supports MySQL/MariaDB and SQLite backups only.');
        }
        clearstatcache(true, $destination);
        if (!is_file($destination) || filesize($destination) === 0) throw new RuntimeException('Empty database backup; update stopped.');
        chmod($destination, 0600);
        echo "DATABASE BACKUP READY\n";
    } else {
        throw new RuntimeException('Use inspect, queue-idle or backup.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Deployment helper: '.$error->getMessage().PHP_EOL);
    exit(1);
}
