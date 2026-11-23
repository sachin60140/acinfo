# Ac Info

Work files, party ledgers and the papers that go between them.

**Deploying? Read [DEPLOY.md](DEPLOY.md) first.** A deploy here is a `git pull`,
so the database does not migrate, the assets do not build and the packages in
`vendor/` do not change unless somebody does it. That list says what to run and
in what order.

Local setup needs PHP 8.4.1 or newer and MySQL 8.4, the database the office
runs and CI tests on; use nothing else. The queries use MySQL's own SQL, which
SQLite does not run. `composer install`, `npm install`, copy `.env.example` to
`.env` and fill in the database's name and password, then
`php artisan migrate`. Tests are `php artisan test` and `npx vitest run`; CI
runs both on every pull request (`.github/workflows/tests.yml`), but run them
here before you push.

## File stickers

Every file received can have a 4 x 6 inch sticker, printed on the counter's
thermal printer and pasted on the folder. It shows:

- the file number and the date it was received;
- the customer and their mobile;
- the registration number, large and boxed, as a plate is read;
- the vehicle owner's mobile;
- the lender, the financer, or the seller and buyer with their mobiles, when a
  work on the file asks for them;
- the works, leaving out cancelled ones, and the file's details.

Anything not typed prints as a line to write on: the seller's mobile, which
may be left empty, and anything on a file received before the sticker. It
shows no amounts and never the vendor.

**Printing it.** After **Receive Files**, a bar offers the stickers for the
whole batch. Print one again from **Edit File** (Print Sticker), or from the
link under the file number in the files list. Each opens the print dialog;
choose the thermal printer with paper **4 x 6 in (100 x 150 mm)**, margins
**None** and scale **100%**.

**What a file asks for** is a setting on its works' types, not a reading of
their names: **Work Types**, *When a File Is Received, Also Ask For*. Lender is
for an HPT, financer for an HPA, seller and buyer for TR, the transfer. Receive
Files and Edit File show those boxes only for a file whose works ask for them,
and keep only what is asked for.

Receive Files will not take a file without them; see
[Required details on Receive Files](#required-details-on-receive-files).

**In the code:** the page is `WorkFileController::stickers()` at
`admin/file/stickers?ids=…` (`&print=1` opens the dialog), drawing
`WorkFileModel::sticker()` into `resources/views/admin/work/stickers.blade.php`,
a page of its own sized with `@page { size: 4in 6in; margin: 0 }`. The details
are columns on `work_file`, and the setting is `work_type.asks_for`; both came
with `2026_11_19_000100_let_a_file_carry_its_sticker_details`, and
[DEPLOY.md](DEPLOY.md#after-particular-deploys) says what that migration
needs on the server. Tests are in `tests/Feature/FileStickerTest.php`.

## Required details on Receive Files

A file's sticker details are taken as it comes in, not left for later.
**Receive Files** will not take a file until these are typed:

| On the file | Required |
| --- | --- |
| Every file | the owner's mobile |
| A work that asks for a lender (HPT) | the lender's name |
| A work that asks for a financer (HPA) | the financer's name |
| A work that asks for the seller and buyer (TR) | the seller's name, the buyer's name and the buyer's mobile |

The seller's mobile may be left empty; it prints as a line on the sticker.
Mobiles are 10 digits. **Edit File** asks for none of these, so a file
received before they were required still saves as it is.

**At the counter.** The boxes still to fill have a red asterisk, the browser
will not send the form while one is empty, and the footer says which file
still needs something (*it still needs the owner's mobile or a detail its
works ask for*). Each file of a batch is checked on its own. A form that gets
past the browser is refused whole by the server, which names the file by its
place in the batch, "File 2 needs the lender's name.", and gives back what was
typed.

**Changing what is asked.** Which works ask for what is the office's setting,
on **Work Types**, and needs no code: a type set to ask for a lender makes the
lender required on every file of that type. What each kind of ask requires is
code, `WorkFileModel::REQUIRED_ON_RECEIPT`, and the receive form keeps its own
copy in `resources/js/components/ReceiveFileRows.vue`: `REQUIRED_FOR`, which
the footer counts from, and the `required` marks on the boxes themselves.
Change all three together, or the form and the server will disagree about what
is missing.

**In the code:** `WorkFileController::receive()` adds
`WorkFileModel::requiredOnReceipt()`, a rule and a message for each file
posted, to its other rules. Each rule carries the box's full check
(`required|digits:10`, not a bare `required`): a rule for one row replaces the
`rows.*` rule before it, so a bare one would let a short mobile through. Tests
are in `tests/Feature/FileStickerTest.php` and `resources/js/receive.test.js`;
[DEPLOY.md](DEPLOY.md#receive-files-asks-for-the-sticker-details) says what to
do on the server and at the counter when it goes live.

## Return Files

Two screens send papers back, each from the sidebar:

- **Return to Customer** (*Return Files to Customer*) hands a file back and
  refunds its charge. Ticking a file fills in what was charged; lower it to keep
  part, or leave it blank to refund all of it. A file charged nothing refunds
  nothing and goes back with the box blank. A refund of 0 on a file that was
  charged is refused: blank is how all of it is said.
- **Return from Vendor** (*Papers Returned by Vendor*) takes a vendor's work
  back and reverses what was booked to them for it. Blank reverses all of it. A
  file shared between two vendors is listed once for each.

**Searching the list.** Each screen has a search box beside **Select all**,
the same as Give to Vendor's, for when the list is longer than the screen and
the customer or vendor at the counter is one name. It finds a file by:

| Screen | What the search reads |
| --- | --- |
| Return to Customer | file number, vehicle, customer, work, details, received date, status |
| Return from Vendor | file number, vehicle, vendor, date given out, work, details, customer |

Every word typed has to be on the row, so each one narrows: `car4sales hpa` is
that customer's hypothecation work, and a plate can be typed as it is read,
`br 01 ab`. A word may match anywhere on the row, so a number such as `01` can
also match a date.

**The search only changes what is shown, never what is sent:**

- A file ticked before the search was typed stays ticked, keeps its amount and
  still goes back. The footer says how many ticked files the search is hiding
  (*1 ticked file is not shown by the search, and still goes back.*), beside
  any error, since the error may be on one of them.
- **Select all** ticks, and unticks, only the files shown.
- The search box posts nothing, and when nothing matches the screen says so.

**In the code:** `resources/js/components/CustomerReturn.vue` and
`VendorReturn.vue` search the same way `GiveToVendor.vue` does. `matches()`
reads each row, and rows that do not match are hidden with `v-show`, never
removed with `v-if`: a removed row would take its tick and amount with it, and
a file ticked earlier would silently stop going back. The server is
`WorkFileController::customerReturn()` and `vendorReturn()`, and the search
never reaches it. Tests are in `resources/js/customer-return.test.js` and
`resources/js/vendor-return.test.js`, and the refunds in
`tests/Feature/WorkFileTest.php`.

---

Everything below is the stock Laravel readme.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
