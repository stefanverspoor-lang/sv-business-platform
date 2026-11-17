<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Maakt een back-up van de centrale database en de databases van SV en YES.
 *
 * Volledig in PHP (via de databaseverbinding), omdat Hostinger het starten
 * van programma's zoals mysqldump vanuit PHP niet toestaat. Elke back-up is
 * een gecomprimeerd .sql.gz-bestand dat je in phpMyAdmin kunt terugzetten.
 * Bewaart standaard de laatste 30 back-ups per database.
 */
class BackupDatabases extends Command
{
    protected $signature = 'crm:backup {--path= : Map voor de back-ups} {--stamp= : Tijdstempel in de bestandsnaam} {--keep=30 : Aantal back-ups per database dat bewaard blijft}';

    protected $description = 'Back-up van alle databases maken';

    public const CONNECTIONS = ['central', 'company_sv', 'company_yes'];

    public function handle(): int
    {
        $dir = $this->option('path') ?: storage_path('app/backups');
        $stamp = $this->option('stamp') ?: now()->format('Ymd-His');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $ok = true;
        foreach (self::CONNECTIONS as $connection) {
            $config = config("database.connections.{$connection}");
            $target = "{$dir}/{$stamp}-{$connection}";

            try {
                if ($config['driver'] === 'sqlite') {
                    copy($config['database'], "{$target}.sqlite");
                } else {
                    $this->dumpMysql($connection, "{$target}.sql.gz");
                }
                $this->line("  {$connection}: OK");
            } catch (Throwable $e) {
                $ok = false;
                report($e);
                $this->components->error("Back-up van {$connection} mislukt: {$e->getMessage()}");
            }

            $this->prune($dir, $connection, (int) $this->option('keep'));
        }

        $ok ? $this->components->info("Back-ups opgeslagen in {$dir}") : $this->components->error('Niet alle back-ups zijn gelukt.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function dumpMysql(string $connection, string $file): void
    {
        $db = DB::connection($connection);
        $pdo = $db->getPdo();
        $gz = gzopen($file.'.part', 'wb6');

        $write = fn (string $sql) => gzwrite($gz, $sql);
        $write('-- SV Business Platform back-up van '.$db->getDatabaseName().' op '.now()->toIso8601String()."\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as [$table]) {
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
            $write("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");

            $stmt = $pdo->query("SELECT * FROM `{$table}`", PDO::FETCH_ASSOC);
            $batch = [];
            $columns = null;
            foreach ($stmt as $row) {
                $columns ??= '`'.implode('`,`', array_keys($row)).'`';
                $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                if (count($batch) === 200) {
                    $write("INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $write("INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        rename($file.'.part', $file);
        chmod($file, 0600);
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
