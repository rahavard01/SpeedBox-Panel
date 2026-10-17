<?php
declare(strict_types=1);

// Standalone: no Laravel bootstrap, .env, database, Go or network access.
try {
    $root = realpath($argv[2] ?? dirname(__DIR__));
    if ($root === false) throw new RuntimeException('Project directory missing.');
    $mode = $argv[1] ?? 'verify';
    $manifest = json_decode(file_get_contents($root.'/agent-dist/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? null) !== 1 || !is_array($manifest['source_files'] ?? null)) {
        throw new RuntimeException('Unsupported agent manifest.');
    }
    $sourceFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/agent', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (str_contains('/'.$relative.'/', '/.git/')) continue;
        if ($file->isLink()) throw new RuntimeException('Agent source symlink: '.$relative);
        if ($file->isFile()) $sourceFiles[] = $relative;
    }
    foreach (['scripts/build-agent.sh', 'scripts/build-agent-from-source.sh', 'scripts/agent-artifacts.php', 'scripts/package-agent.py'] as $path) $sourceFiles[] = $path;
    sort($sourceFiles, SORT_STRING);
    $expected = array_keys($manifest['source_files']);
    sort($expected, SORT_STRING);
    if ($sourceFiles !== $expected) throw new RuntimeException('Agent source inventory changed; rebuild and package the binaries before publishing.');
    foreach ($sourceFiles as $path) {
        if (!preg_match('~^(agent|scripts)/[A-Za-z0-9_./-]+$~D', $path) || str_contains($path, '..') || is_link($root.'/'.$path)) {
            throw new RuntimeException('Unsafe agent source path.');
        }
        $data = file_get_contents($root.'/'.$path);
        if ($data === false || !hash_equals($manifest['source_files'][$path], hash('sha256', str_replace("\r\n", "\n", $data)))) {
            throw new RuntimeException('Agent binary/source mismatch: '.$path.'; rebuild and package before publishing.');
        }
    }
    foreach (['amd64' => 62, 'arm64' => 183] as $arch => $machine) {
        $archive = $root.'/agent-dist/linux-'.$arch.'.tar.gz';
        $entry = $manifest['archives'][$arch] ?? null;
        if (!is_array($entry) || is_link($archive) || !is_file($archive) || filesize($archive) !== ($entry['bytes'] ?? -1)
            || !hash_equals($entry['sha256'], hash_file('sha256', $archive))) {
            throw new RuntimeException('Agent archive missing or checksum mismatch: linux-'.$arch.'.tar.gz');
        }
        if ($mode === 'extracted') {
            $directory = realpath($argv[3] ?? '');
            if ($directory === false) throw new RuntimeException('Extracted directory missing.');
            foreach (['xboard-node', 'xbctl'] as $program) {
                $name = $program.'-linux-'.$arch;
                $path = $directory.'/'.$name;
                $binary = $entry['binaries'][$name] ?? null;
                if (!is_array($binary) || is_link($path) || !is_file($path) || filesize($path) !== ($binary['bytes'] ?? -1)
                    || !hash_equals($binary['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('Extracted agent checksum mismatch: '.$name);
                }
                $stream = fopen($path, 'rb');
                $header = fread($stream, 20);
                fclose($stream);
                if (strlen($header) !== 20 || substr($header, 0, 6) !== "\x7fELF\x02\x01" || unpack('v', substr($header, 18, 2))[1] !== $machine) {
                    throw new RuntimeException('Wrong Linux binary architecture: '.$name);
                }
            }
        }
    }
    if (!in_array($mode, ['verify', 'extracted'], true)) throw new RuntimeException('Unknown artifact verification mode.');
    echo 'AGENT ARTIFACTS OK: source and '.($mode === 'extracted' ? '4 Linux binaries' : '2 archives').PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'AGENT ARTIFACTS FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
