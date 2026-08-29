# Contributing

Thanks for helping. This is a library anyone can add to, and that includes the code.

## Getting set up

```bash
git clone <your fork>
cd DigitalLibrary
composer install
cp .env.example .env          # edit the DB_* values
php cli/console.php key:generate
php cli/console.php db:create
php cli/console.php migrate
php cli/console.php serve
```

Full setup, including Docker, is in [DEVDOC.md](./DEVDOC.md).

## Before you open a pull request

```bash
composer check   # runs phpcs, phpstan and phpunit
```

All three have to pass. CI runs the same commands plus a migration rollback and
a health check against a real MySQL.

The account tests need a database and skip themselves unless `DB_DATABASE`
contains "test". Running them locally is worth it before touching anything under
`app/Services` or `app/Repositories`: see
[DEVDOC.md](./DEVDOC.md#the-database-tests).

## House rules

- **PSR-12**, enforced by `phpcs`. `composer lint:fix` fixes most of it.
- **PHPStan level 6**, no baseline, no `@phpstan-ignore`. If the analyser is
  unhappy the type is usually genuinely wrong.
- **No SQL outside `app/Repositories`** (and `app/Core/Migrator.php`). Controllers
  call services, services call repositories.
- **Every query is a prepared statement.** There is no method on `Db` that takes
  an interpolated value, and there should never be one.
- **Escape on output**: `$this->e(...)` in every view. The only unescaped output
  is `$this->slot(...)`, which holds already-rendered HTML.
- **No inline `<script>`**: the Content-Security-Policy forbids it. JavaScript
  lives in `public/assets/js`.
- **Docs travel with the code.** A change that adds a route, a table, an env var
  or a user-facing feature updates `DEVDOC.md` or `README.md` in the same pull
  request.
- **Commit messages are one line**, present tense, no body.
- No em dashes in code, comments, docs or commit messages.

## Adding a migration

Create `database/migrations/NNNN_short_description.sql` with the next number and
both sections:

```sql
-- @up
CREATE TABLE `example` (...);

-- @down
DROP TABLE IF EXISTS `example`;
```

Statements are split on a semicolon at the end of a line, so keep a statement
that contains a semicolon inside a string on one line. Every migration needs a
working `@down`; CI rolls the whole batch back and re-applies it.

## Adding a route

1. Register it in `routes/web.php` or `routes/api.php` and give it a name.
2. Put the controller in `app/Controllers/Web` or `app/Controllers/Api`. Controllers
   take their dependencies through the constructor and return a `Response`.
3. Web routes that change state need the CSRF field in their form.
4. Add a feature test under `tests/Feature`.

## Adding a permission

Permissions are string keys in the `permissions` table, mapped onto roles by
`role_permissions`. To add one:

1. Write a migration inserting the key with a description and an area, and
   inserting the `role_permissions` rows for the roles that should have it. Do
   not edit migration 0003; it has already run everywhere.
2. Enforce it: `->middleware(Authorize::class . ':your.key')` on the route, or
   `$gate->authorize('your.key')` inside the controller when the check depends on
   the request.
3. Hide what it guards in the templates with `$this->gate->allows('your.key')`,
   so nobody is shown a link that will 403.
4. Add a case to `tests/Feature/PermissionTest.php`, and update the role table in
   `README.md` if it changes what a role can do.

## Adding to the catalogue

Anything that reads or writes `books` and its related tables goes through
`BookRepository` and `BookService`; a controller should not assemble a record
itself. If you add a column:

1. Migration with `@up` and `@down`.
2. Add it to `Book::fromRow()` and the `COLUMNS` constant in `BookRepository`.
3. Handle it in `BookService::create()` and `update()`, where the input is
   normalised (year ranges, ISBN digits, language codes).
4. Show it on `pages/books/show.php` and add the field to `pages/books/form.php`.
5. Test it in `tests/Feature/BookFormTest.php`.

The seed books in `database/seeds/books.php` are public domain works with a
`source_url`. Add to them only what is genuinely free to redistribute.

## Adding a kind of thing to the queue

The moderation engine is generic. To put a new subject type through it:

1. Add a case to `App\Support\ModerationType`.
2. Open the request where the submission happens:
   `$moderation->open(ModerationType::YourThing, $subjectId, $title, $payload, $user)`.
3. Add a branch to `ModerationService::apply()` saying what approval and
   rejection actually do to the subject. That match is deliberately exhaustive:
   PHP will complain if you add a case and forget the branch.
4. Show it on the review screen if it needs more than a title
   (`app/Views/pages/librarian/review.php`).
5. Test the approve and the reject path in
   `tests/Feature/ModerationQueueTest.php`.

Do not decide anything outside the engine. The claim lock, the self-review rule,
the event log and the notification all live there, and a second code path that
skips them is a second set of rules.

## Anything that answers a book request

Fulfilment happens in one place, `BookRequestService::fulfil()`, and it is
reached from the approval path rather than from a controller. If you add another
way for a book to arrive, carry the `request_id` into the moderation payload and
let the approval answer it; do not close the request in the controller as well,
or the voters get told twice.

## Touching a collection

`CollectionService::canEdit()` and `canView()` are the only two places that
decide who may do what to a collection, and both are asked from more than one
screen. Add a new way to change a collection and route it through the service;
do not re-derive ownership in a controller.

A private collection must 404 rather than 403: whether someone has a shelf called
"Job Applications" is not information the site should give away.

## Touching the reader

`public/assets/js/reader.js` is the only file that talks to PDF.js or epub.js,
and it reads everything it needs from data attributes on `[data-reader]`. Keep it
that way: the CSP has no `unsafe-inline`, so a value cannot be passed by
generating a script tag.

If you add a format, add it to `BookFile::isReadable()` and `readerKind()` and
give it a branch in the script. Say what `position` means for it in DEVDOC: it is
a free-form string and only the reader that wrote it can interpret it.

## Anything that pays reputation

Call `ReputationService::award()` with an action from `ReputationAction`; do not
add points to the user row directly, or the events and the total drift apart and
the badges stop making sense. Pass a subject type and id whenever the same thing
could be awarded twice.

A new badge is a migration inserting a row into `badges`: a name, the action to
count and the threshold. No code changes.

## Adding a setting

Two places: a default in a migration (so a fresh install has it) and an entry in
the `FIELDS` list in `Admin\SettingsController` (so an admin can change it).
Read it where it is used, through `SettingsRepository`, rather than at boot:
that is what makes a change take effect without a deploy. Fall back to the
config value or a literal, so the site still works if the row is missing.

## Adding a page that needs a session

Put the route inside the `Authenticate::class` group in `routes/web.php`. Signed
out visitors are redirected to `/login` with the path remembered, so they land
back where they were going. For a page that only makes sense signed out, use
`RedirectIfAuthenticated`.

## Adding a translated string

The key is the English string itself, so the first step is to wrap it:

```php
<?php echo $this->t('Waiting for review'); ?>
```

That alone is a complete change: an untranslated key renders as the English it
already was. To translate it, add the same string as a key in
`resources/lang/hi.php`. Keep the two files in the same order so a missing line
is visible in a diff. Placeholders are `:name` and are filled from the second
argument.

Do not wrap catalogue content: a book's title, its description and a member's
review are shown as they were written. Translate the interface around them.

Adding a whole language is one file, `resources/lang/<two letters>.php`; the
footer switcher lists whatever it finds, so there is nothing else to register.

## Adding an API route

`routes/api.php` only. Everything in there is public, read-only and rate
limited, and it must stay that way: if a route needs to know who is calling, it
belongs in `routes/web.php` instead. Return through `Response::json()`, reuse
the repository the HTML page uses rather than writing a second query, and give
the route a `RateLimit` middleware with a limit that suits how expensive it is.

## What to work on

Issues labelled `good first issue` are scoped to a single file or a single
route. The milestone list in `PLAN.md` says what is being built next; if you want
to take a whole milestone item, say so on the issue first so two people do not
build the same thing.

## Reporting a security issue

Do not open a public issue. Email the maintainer at adaridileep@gmail.com with
the details and give a reasonable window for a fix before disclosing.
