<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'inbaf:backup';
    protected $description = 'Create a private database backup using the configured database engine.';

    public function handle(): int
    {
        $driver = config('database.default');
        $config = config("database.connections.{$driver}");
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');
        $target = $disk->path('backups/inbaf-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)));
        if ($driver === 'sqlite') {
            // VACUUM INTO gives a consistent copy even when the database uses WAL.
            $target .= '.sqlite';
            $quoted = \Illuminate\Support\Facades\DB::getPdo()->quote($target);
            \Illuminate\Support\Facades\DB::statement("VACUUM INTO {$quoted}");
        } elseif (in_array($driver, ['mysql', 'pgsql'])) {
            $target .= '.sql';
            $args = $driver === 'mysql'
                ? ['mysqldump', '--single-transaction', '--routines', '--triggers', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--result-file='.$target, $config['database']]
                : ['pg_dump', '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--no-password', '--file='.$target, $config['database']];
            $environment = $driver === 'mysql' ? ['MYSQL_PWD' => $config['password']] : ['PGPASSWORD' => $config['password']];
            $process = new Process($args, base_path(), $environment, null, 300);
            $process->run();
            if (! $process->isSuccessful()) {
                if (is_file($target)) unlink($target);
                $this->error('Backup failed. Ensure the database backup executable is installed and credentials permit reads.');
                return self::FAILURE;
            }
        } else {
            $this->error('Unsupported database engine.');
            return self::FAILURE;
        }
        $this->info('Private backup created: '.$target);
        return self::SUCCESS;
    }
}
