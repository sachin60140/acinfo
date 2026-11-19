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
migrate, the assets do not build, the packages in `vendor/` do not change, and
nothing warns you. That is what this list is for.

## Before you push

**Run both suites.** CI runs them on every pull request
(`.github/workflows/tests.yml`), but run them here first, so a red build is
news rather than the first anyone hears of it.

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

**A change to `composer.json` or `composer.lock` does not deploy by pulling.**
`vendor/` is not in the repository, so the server needs the packages built and
carried to it: see [When the packages change](#when-the-packages-change). Say so
in the pull request, so whoever deploys it knows.

## On the server

Run these in the application directory, in this order.

**1. Back it up first.** Every time, before anything else. Migrations that
touch indexes are the ones worth having a way back from.

```bash
php artisan db:backup
```

The dashboard's **Last Backup** tile reads `storage/app/backups` and turns
amber after two days without a backup and red after four. A backup only before
each deploy will leave it red most weeks — set one up nightly from the hosting
panel's cron jobs instead (keep the default folder, or the tile cannot see it):

```bash
cd /home/<user>/domains/<site> && php artisan db:backup --keep=14
```

**2. See whether the packages change.**

```bash
git fetch
git diff --stat HEAD @{u} -- composer.json composer.lock
```

Nothing listed: go on to step 3. Either file listed: this deploy changes
`vendor/`. Build the packages and unpack them on the server first, as
[When the packages change](#when-the-packages-change) says, then come back here.
The site keeps running on the old code and the old packages while you do.

**3. Pull the code.**

```bash
git pull
```

**4. Put the new packages in, if step 2 listed anything.** Straight after the
pull: until this is done the site runs the new code on the old packages.

```bash
mv vendor vendor.old && mv vendor.new vendor
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover
php artisan optimize:clear
php artisan --version
```

The two cache files have to go. They list the packages the server had, and
Laravel loads every one of them on every request, artisan included: with one of
them missing from the new `vendor/` (a development package, say, which a
production build leaves out) every page and every command stops with
`Class "…ServiceProvider" not found`, `package:discover` included. Once they
are deleted, `package:discover` writes them again from what is now in `vendor/`. The version printed last
should be the `laravel/framework` version in `composer.lock`.

**5. Run the migrations.** `--force` because the server is not interactive and
will otherwise stop to ask.

```bash
php artisan migrate --force
```

A few migrations leave something to be done in the application once they have
run. Those are listed under
[After particular migrations](#after-particular-migrations); look there for any
this pull brought in.

**6. Check it took.** Everything should say `Ran`.

```bash
php artisan migrate:status
```

**7. Open a screen.** The work files list touches most of the schema, so it is
the one worth loading. A `Column not found` here means step 5 did not happen.

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

## After particular migrations

What a migration needs besides step 5, newest first.

### File stickers: `2026_11_19_000100_let_a_file_carry_its_sticker_details`

Brought in by the 4 x 6 inch file sticker (November 2026). It adds what the
sticker prints and the file never held:

- on `work_file`: the vehicle owner's mobile, a lender, a financer, and a
  seller and a buyer, each with a mobile;
- on `work_type`: `asks_for`, the setting that says which of those a work
  needs.

Every column is new, so nothing already saved changes; the only thing it fills
in is the work types' starting setting, below. It needs no new packages; step 5
runs it like any other.

**Check it took.** In step 6 it should say `Ran`. Then open **Receive Files**:
each file should have an **Owner's Mobile** box. A `Column not found` naming
`owner_mobile` or `asks_for` means step 5 did not run.

**Check the work types it set, and set any it could not.** It gives a starting
setting to the types named exactly HPT (lender), HPA (financer) and TR, the
transfer (seller and buyer), ignoring case and spaces either side. A type
named TO gets the same as TR. It changes no type that already has a setting.
Every other type asks for nothing until it is set, so Receive Files shows no
boxes for it and its sticker has no lines for them. Open **Work Types**: each
type with a setting shows "Asks for …" under its name. For any that needs one
and has none, edit the type, choose under **When a File Is Received, Also Ask
For**, and save.

**Set up the printer** on the counter's computer. The sticker page opens the
browser's print dialog by itself. In it, choose the thermal printer with paper
size **4 x 6 in (100 x 150 mm)**, margins **None** and scale **100%**. The page
repeats these above the stickers. If the printer offers no
4 x 6 in paper, add that size in its own driver settings first.

**Going back** needs only the code. The code from before the pull never reads
these columns, and they are nullable, so `git reset --hard ORIG_HEAD` is enough.
Leave the columns where they are: rolling the migration back would drop them,
and with them every mobile and name typed in since the deploy.

## When the packages change

The host cannot run Composer, and `vendor/` is not in the repository. So when
`composer.lock` changes, the packages are built on another machine and carried
to the server, and until they are the server runs on the old ones: in November
2026 that would have been a Laravel with a published authentication bypass in
it, whatever the pulled code said.

**Build them** on any machine with PHP 8.4 and Composer; the one you develop on
will do. In a folder of its own, at the commit the server is about to pull:
`--no-dev` leaves out PHPUnit and the rest, and run in your own checkout it
would take them out of that too.

```bash
git fetch
git worktree add --detach ../acinfo-release origin/main
cd ../acinfo-release
composer install --no-dev --optimize-autoloader --no-interaction
tar --exclude=.git -czf ../vendor.tar.gz vendor
cd -
git worktree remove --force ../acinfo-release
```

`--exclude=.git` matters when Composer could not download a package's release
and cloned it instead: the history comes with the clone, and it made one
archive 290 MB where 19 was enough.

The packages ask for PHP 8.4.1 or newer, here and on the server. On an older
PHP the site stops with Composer's own message naming the version it needs,
rather than failing somewhere less clear.

**Carry them to the server.** Upload `vendor.tar.gz` to the application
directory with the hosting panel's file manager or SFTP: the application
directory, not `public/`, which is the part the web can download from. Then,
in the application directory:

```bash
mkdir vendor.new
tar -xzf vendor.tar.gz -C vendor.new --strip-components=1
ls vendor.new/autoload.php
```

The last line should print the file's name back. Then return to step 3 of
[On the server](#on-the-server).

**If the site breaks on the new packages,** put the old ones back. They are
`vendor.old`, kept by step 4 for exactly this:

```bash
mv vendor vendor.bad && mv vendor.old vendor
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover
git reset --hard ORIG_HEAD
```

The last line puts the code back to before the pull (`git pull` leaves where it
started in `ORIG_HEAD`; use it before any other git command). New code may need
the new packages, so the two go back together. If step 5 also ran migrations,
going back is a bigger decision than this, and the backup from step 1 is what
it is for.

**When the site has run a day on the new packages,** clear up:

```bash
rm -rf vendor.old vendor.bad vendor.tar.gz
```
