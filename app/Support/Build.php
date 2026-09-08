<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Which build of this application is actually running.
 *
 * Deployment here is a git pull on a checkout, with no composer and no npm on
 * the server, so the usual question after a deploy is "did that land?" — and
 * the only honest answer is one the running code works out for itself. A number
 * typed into a config file answers whatever it was last set to.
 *
 * So the timestamp is read from git's own bookkeeping rather than written down.
 * A pull fast-forwards the branch and rewrites the file the branch is kept in;
 * that file's modified time is therefore the moment this code arrived, on the
 * server and on a laptop alike. Nothing is shelled out — exec is off on plenty
 * of shared hosts, and this has to work where it is deployed rather than where
 * it was written.
 *
 * The version beside it is a human's decision and stays one: VERSION at the
 * project root, bumped when a release is worth naming. The stamp is what moves
 * on its own.
 */
class Build
{
    /** Shown when the version file is missing, rather than a made-up number. */
    private const UNVERSIONED = '0.0.0';

    /**
     * Worked out once per request. Every page draws this in the footer, and the
     * answer cannot change while a single response is being built.
     *
     * @var array<string, mixed>
     */
    private static array $memo = [];

    /** The release name, from VERSION at the project root. */
    public static function version(): string
    {
        return self::$memo['version'] ??= self::readVersion(base_path('VERSION'));
    }

    public static function readVersion(string $path): string
    {
        if (! is_file($path)) {
            return self::UNVERSIONED;
        }

        $raw = trim((string) file_get_contents($path));

        /*
         * Anything but digits and dots is somebody else's idea of a version
         * file — a changelog heading, a stray "v", a comment. The prefix is
         * added when it is printed, so a file saying "v1.1.1" does not become
         * "vv1.1.1".
         */
        return preg_match('/^v?(\d+(?:\.\d+)*)/', $raw, $m) ? $m[1] : self::UNVERSIONED;
    }

    /** When this code arrived, in the timezone the rest of the app uses. */
    public static function at(): CarbonImmutable
    {
        $stamp = self::$memo['at'] ??= self::newestMtime(base_path()) ?? time();

        return CarbonImmutable::createFromTimestamp($stamp, config('app.timezone'));
    }

    /** yyyymmdd-hhmm, the form in the footer. */
    public static function stamp(): string
    {
        return self::at()->format('Ymd-Hi');
    }

    /**
     * The commit this build was made from, short.
     *
     * The version beside it is a release name and only moves when a release is
     * worth naming, so on its own it cannot tell two builds apart. This can:
     * it changes every commit, and it points at exactly one of them, which
     * turns "is that deployed?" from a judgement into a lookup.
     *
     * Read out of git's own files. Loose ref first, then packed-refs for a
     * checkout git has tidied, then HEAD itself when it names a commit
     * directly rather than a branch.
     */
    public static function commit(): ?string
    {
        if (array_key_exists('commit', self::$memo)) {
            return self::$memo['commit'];
        }

        return self::$memo['commit'] = self::commitIn(base_path());
    }

    public static function commitIn(string $base): ?string
    {
        $git = self::gitDir($base);

        if ($git === null) {
            return null;
        }

        $ref = self::headRef($git);

        // Detached: HEAD is the commit rather than a pointer to a branch.
        if ($ref === null) {
            return self::shortSha(@file_get_contents($git.'/HEAD'));
        }

        if (is_file($git.'/'.$ref)) {
            return self::shortSha(@file_get_contents($git.'/'.$ref));
        }

        return self::packedSha($git, $ref);
    }

    /**
     * The line in packed-refs for one ref.
     *
     * The file holds more than branches: a header comment, and a peeled target
     * under each annotated tag. Neither can be told apart by position, so a
     * line counts only when it names this ref *and* carries something that is
     * actually a commit id — and a line that fails either test is stepped over
     * rather than ending the search.
     *
     * That last part matters more than it looks. Returning on the first line
     * whose name matched let a malformed entry — "# refs/heads/main", left by
     * somebody editing the file — shadow the real one below it, and the build
     * would report no commit at all while sitting on one.
     */
    private static function packedSha(string $git, string $ref): ?string
    {
        $packed = @file_get_contents($git.'/packed-refs');

        if ($packed === false) {
            return null;
        }

        foreach (preg_split('/\R/', $packed) as $line) {
            [$sha, $name] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);

            if ($name !== $ref) {
                continue;
            }

            if (($short = self::shortSha($sha)) !== null) {
                return $short;
            }
        }

        return null;
    }

    /** Forty hex characters, or nothing. Seven is what a person reads out. */
    private static function shortSha($raw): ?string
    {
        $sha = strtolower(trim((string) $raw));

        return preg_match('/^[0-9a-f]{40}$/', $sha) ? substr($sha, 0, 7) : null;
    }

    /**
     * The whole thing: v1.1.1 · 20260907-2121 · 44a52c5
     *
     * The commit is left off rather than faked when there is nothing to read
     * it from — a build that cannot say which commit it is should say so by
     * being quiet, not by printing something that looks like an answer.
     */
    public static function label(): string
    {
        $parts = ['v'.self::version(), self::stamp()];

        if (($commit = self::commit()) !== null) {
            $parts[] = $commit;
        }

        return implode(' · ', $parts);
    }

    /**
     * The newest of the files that move when the code does.
     *
     * The branch ref first, because that is what a pull rewrites. Packed refs
     * next: git folds loose refs into one file when it tidies up, and a
     * checkout that has been gc'd has no refs/heads/main to stat. Then the
     * fallbacks, for a copy deployed without .git at all — the asset manifest
     * moves on every front-end build and composer.lock on every dependency
     * change.
     *
     * The newest rather than the first found: a repository can carry all of
     * these, and the most recent of them is the one that says when this code
     * became what it is.
     */
    public static function newestMtime(string $base): ?int
    {
        $git = self::gitDir($base);

        $candidates = [
            $base.'/public/build/manifest.json',
            $base.'/composer.lock',
        ];

        if ($git !== null) {
            $candidates[] = $git.'/HEAD';
            $candidates[] = $git.'/packed-refs';

            if (($ref = self::headRef($git)) !== null) {
                $candidates[] = $git.'/'.$ref;
            }
        }

        $newest = null;

        foreach ($candidates as $path) {
            /*
             * PHP remembers what it last learned about a file for a couple of
             * minutes. Without this, the first refresh after a deploy shows the
             * stamp from before it — which is precisely the moment somebody is
             * looking at this to find out whether the deploy landed, and
             * precisely the wrong answer to give them.
             *
             * Four paths, once per request: the cost is nothing beside being
             * wrong for two minutes.
             */
            clearstatcache(true, $path);

            if (! is_file($path)) {
                continue;
            }

            $at = @filemtime($path);

            if ($at !== false && ($newest === null || $at > $newest)) {
                $newest = $at;
            }
        }

        return $newest;
    }

    /**
     * Where git keeps its bookkeeping, which is not always a directory called
     * .git — in a worktree it is a file naming somewhere else.
     */
    private static function gitDir(string $base): ?string
    {
        $path = $base.'/.git';

        if (is_dir($path)) {
            return $path;
        }

        if (! is_file($path)) {
            return null;
        }

        $line = trim((string) file_get_contents($path));

        if (! str_starts_with($line, 'gitdir:')) {
            return null;
        }

        $dir = trim(substr($line, strlen('gitdir:')));

        // Relative in a worktree, absolute in a submodule.
        if (! preg_match('#^(/|[A-Za-z]:)#', $dir)) {
            $dir = $base.'/'.$dir;
        }

        return is_dir($dir) ? $dir : null;
    }

    /**
     * The branch HEAD points at, as a path under the git directory, or null
     * when HEAD is detached and names a commit directly.
     */
    private static function headRef(string $git): ?string
    {
        $head = @file_get_contents($git.'/HEAD');

        if ($head === false || ! str_starts_with($head, 'ref:')) {
            return null;
        }

        $ref = trim(substr($head, strlen('ref:')));

        // It goes into a path, so it may only look like one.
        return preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) && ! str_contains($ref, '..')
            ? $ref
            : null;
    }

    /** For tests, which need each case to start without the last one's answer. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
