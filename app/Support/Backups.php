<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The backups db:backup writes, and how long ago the last one was.
 *
 * Read from the folder rather than remembered in the database. A file gets its
 * final name only once the dump has finished (see BackupDatabase), so a name
 * is proof a backup was written, and the name carries when. A row saying "last
 * backup" would be missing from the very dump it describes, and the backup's
 * own tests run it against the live connection, where such a row would be left
 * pointing at a folder that has since been deleted.
 */
class Backups
{
    /** Two days without one is a night missed; four is a problem. */
    public const WARN_DAYS = 2;

    public const BAD_DAYS = 4;

    /**
     * Where db:backup writes unless told otherwise, and where the dashboard
     * looks — one answer for both, so neither can be looking somewhere the
     * other is not. backups.path moves it (a test's own folder, say).
     */
    public static function directory(): string
    {
        return config('backups.path') ?: storage_path('app/backups');
    }

    /**
     * The backups db:backup wrote for this database, oldest first.
     *
     * Matched by the whole name, not a prefix: a copy somebody named by hand —
     * "acinfo-before-the-release.sql.gz" — is theirs to keep, and is not the
     * night's backup however it sorts; nor is a file still being written, nor
     * one for another database whose name starts the same way.
     *
     * @return list<string> paths
     */
    public static function written(string $directory, string $database): array
    {
        $pattern = '/^'.preg_quote($database, '/').'-\d{4}-\d{2}-\d{2}-\d{4}\.sql\.gz$/';

        $files = array_values(array_filter(
            glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$database.'-*.sql.gz') ?: [],
            fn ($path) => preg_match($pattern, basename($path)) === 1
        ));

        // The names carry the date, so they sort by age.
        sort($files);

        return $files;
    }

    /**
     * The newest backup, and what the dashboard should say about it.
     *
     * tone: 'ok' up to a day old, 'warn' from WARN_DAYS, 'bad' from BAD_DAYS —
     * and 'bad' when there is none at all. Days are calendar days, so last
     * night's backup is "Yesterday" at nine in the morning.
     *
     * @return array{at: CarbonImmutable|null, days: int|null, bytes: int|null, tone: string, unfinished: bool}
     */
    public static function latest(?string $directory = null, ?string $database = null): array
    {
        $directory ??= self::directory();
        $database ??= DB::connection()->getDatabaseName();

        // A file the web server saw a moment ago may have been pruned since.
        clearstatcache();

        $files = self::written($directory, $database);
        $newest = end($files) ?: null;

        $at = $newest ? self::takenAt(basename($newest), $database) : null;
        $days = $at ? max(0, (int) $at->startOfDay()->diffInDays(now()->startOfDay())) : null;

        return [
            'at' => $at,
            'days' => $days,
            'bytes' => $newest ? (int) @filesize($newest) : null,
            'tone' => match (true) {
                $days === null => 'bad',
                $days >= self::BAD_DAYS => 'bad',
                $days >= self::WARN_DAYS => 'warn',
                default => 'ok',
            },
            'unfinished' => self::unfinished($directory, $database),
        ];
    }

    /**
     * Whether a backup was started and never finished — the night's run
     * killed part way, by the host or by a time limit — and left its half-written
     * file behind. Given an hour, so one being written right now is not it.
     */
    private static function unfinished(string $directory, string $database): bool
    {
        foreach (glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$database.'-*.sql.gz.writing') ?: [] as $partial) {
            $modified = @filemtime($partial);

            if ($modified !== false && $modified < now()->subHour()->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /** "acinfo-2026-09-23-0130.sql.gz" was written at 01:30 on 23-09-2026, office time. */
    private static function takenAt(string $name, string $database): ?CarbonImmutable
    {
        $stamp = substr($name, strlen($database) + 1, 15);

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d-Hi', $stamp, config('app.timezone')) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** 1.4 MB, 380 KB, 12 B. */
    public static function readable(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
