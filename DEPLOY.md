# Deploying acinfo

## Why this file exists

On 19 September 2026 the work files list stopped loading on the live site:

> SQLSTATE[42S22]: Column not found: 1054 Unknown column 'vendor_item.vendor_id'

Nothing was wrong with the code. `work_file_item.vendor_id` is added by
`2026_09_19_000100_give_each_work_its_own_vendor`, and that migration had never
been run on the server. The code had been pulled; the schema had not moved with
it, and every screen that reads a work's own vendor failed the moment it was
asked for.

A deploy here is a `git pull`, so nothing runs by itself. The database does not
migrate, the assets do not build, and nothing warns you. That is what this list
is for.

## Before you push

**Run both suites.** Neither runs anywhere else — there is no CI.

```bash
php artisan test
npx vitest run
```

**Built assets travel in the repository.** The host has no npm step, so if you
touched anything under `resources/js` or `resources/css`, the compiled bundle
has to go with the commit or the server keeps serving the old one:

```bash
npm run build
```

Then commit `public/build` along with your change. It is deliberately not
ignored — see the note in `.gitignore`.

## On the server

Run these in the application directory, in this order.

**1. Back it up first.** Every time, before anything else. Migrations that
touch indexes are the ones worth having a way back from.

```bash
php artisan db:backup
```

**2. Pull the code.**

```bash
git pull
```

**3. Run the migrations.** `--force` because the server is not interactive and
will otherwise stop to ask.

```bash
php artisan migrate --force
```

**4. Check it took.** Everything should say `Ran`.

```bash
php artisan migrate:status
```

**5. Open a screen.** The work files list touches most of the schema, so it is
the one worth loading. A `Column not found` here means step 3 did not happen.

## When a migration refuses

Some migrations stop rather than guess, and they change nothing when they do.
The one above will not add the ledger's wider unique key if the ledger already
holds two entries for the same file, role and party:

> party_ledger already holds duplicate entries for the same file, role and
> party, so the wider unique index cannot be added: … Decide which entry is
> correct, delete the others, then run this migration again.

That is the safe outcome, not a broken deploy. Work out which of those rows is
the real one — the wrong choice takes money off somebody's statement — delete
the others, and run `php artisan migrate --force` again.

Migrations are written to be safe to re-run: columns are added only when they
are missing, and backfills only touch rows that have not been filled in.

## What this list does not cover

**Composer dependencies.** The host cannot run composer and `vendor/` is not in
the repository, so a change to `composer.json` does not reach the server by
pulling. Whoever set the hosting up knows how `vendor/` gets there; write it
down here when you next do it, because it is the other half of this problem and
it will bite the same way.
