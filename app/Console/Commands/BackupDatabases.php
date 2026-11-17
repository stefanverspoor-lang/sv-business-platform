<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Maakt een back-up van de centrale database en de databases van SV en YES.
 * Draait dagelijks via de planner en bij elke update (deploy.sh).
 * Bewaart standaard de laatste 30 back-ups per database.
 */
class BackupDatabases extends Command
{
    protected $signature = 'crm:backup {--path= : Map voor de back-ups} {--stamp= : Tijdstempel in de bestandsnaam} {--keep=30 : Aantal back-ups per database dat bewaard blijft}';

    protected $description = 'Back-up van alle databases maken';

    public function handle(): int
    {
        $dir = $this->option('path') ?: storage_path('app/backups');
        $stamp = $this->option('stamp') ?: now()->format('Ymd-His');
        @mkdir($dir, 0700, true);

        $ok = true;
        foreach (['central', 'company_sv', 'company_yes'] as $connection) {
            $config = config("database.connections.{$connection}");
            $target = "{$dir}/{$stamp}-{$connection}";

            if ($config['driver'] === 'sqlite') {
                $ok = copy($config['database'], "{$target}.sqlite") && $ok;
            } else {
                $ok = $this->mysqlDump($config, "{$target}.sql.gz") && $ok;
            }

            $this->prune($dir, $connection, (int) $this->option('keep'));
        }

        $ok ? $this->components->info("Back-ups opgeslagen in {$dir}") : $this->components->error('Niet alle back-ups zijn gelukt.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function mysqlDump(array $config, string $file): bool
    {
        // Wachtwoord via een tijdelijk bestand, zodat het niet in de proceslijst staat.
        $cnf = tempnam(sys_get_temp_dir(), 'crm');
        file_put_contents($cnf, "[client]\nuser=\"{$config['username']}\"\npassword=\"{$config['password']}\"\nhost=\"{$config['host']}\"\nport=\"{$config['port']}\"\n");
        chmod($cnf, 0600);

        $process = Process::fromShellCommandline(
            'mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --no-tablespaces "$DB" | gzip > "$OUT"',
            null,
            ['CNF' => $cnf, 'DB' => $config['database'], 'OUT' => $file],
            null,
            600,
        );
        $process->run();
        @unlink($cnf);

        if (! $process->isSuccessful() || filesize($file) < 100) {
            $this->components->error("Back-up van {$config['database']} mislukt: ".$process->getErrorOutput());

            return false;
        }

        chmod($file, 0600);

        return true;
    }

    protected function prune(string $dir, string $connection, int $keep): void
    {
        $files = glob("{$dir}/*-{$connection}.*") ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
