# Digital Library - Developer Documentation

Technical reference for the Digital Library codebase: architecture, request flow,
data access, security model and setup. For what the app does from a user's point
of view, see [README.md](./README.md). For the design of the features that are
not built yet, see [PLAN.md](./PLAN.md).

This file describes the code that exists today, at milestones 0 to 9.

## Table of contents

- [Tech stack](#tech-stack)
- [Architecture overview](#architecture-overview)
- [Directory layout](#directory-layout)
- [Routing](#routing)
- [Controllers and the container](#controllers-and-the-container)
- [Views and theming](#views-and-theming)
- [The interface](#the-interface)
- [Timestamps](#timestamps)
- [Auth model](#auth-model)
- [Permissions](#permissions)
- [The catalogue](#the-catalogue)
- [Uploads and moderation](#uploads-and-moderation)
- [Book requests](#book-requests)
- [Collections](#collections)
- [Reading](#reading)
- [Reviews and reputation](#reviews-and-reputation)
- [Running the library](#running-the-library)
- [The public API and feeds](#the-public-api-and-feeds)
- [Rate limiting](#rate-limiting)
- [Translations](#translations)
- [Accessibility](#accessibility)
- [Data access](#data-access)
- [Data model](#data-model)
- [Migrations](#migrations)
- [Mail](#mail)
- [File storage](#file-storage)
- [Security model](#security-model)
- [Console commands](#console-commands)
- [Environment variables](#environment-variables)
- [Testing](#testing)
- [Continuous integration](#continuous-integration)
- [Local development](#local-development)
- [Deployment](#deployment)
- [Known constraints and gotchas](#known-constraints-and-gotchas)

## Tech stack

PHP 8.3 (minimum 8.2), MySQL 8 or MariaDB 10.6 over PDO, plain PHP templates and
vanilla JavaScript. There is no framework: `app/Core` is about 2,000 lines of
router, container, view, session, validator, migrator, mailer and PDO wrapper,
which is the whole runtime. Composer carries the dev tooling (PHPUnit, PHPStan,
PHP_CodeSniffer) and exactly one runtime package, PHPMailer, which is used only
when `MAIL_DRIVER=smtp`. The app still boots and runs with no `vendor/` at all.

This is a deliberate trade: more code we own in exchange for a codebase a new
contributor can read end to end in an afternoon, and a deploy that works on
shared hosting. Laravel and Slim were both considered; see PLAN.md section 2.

## Architecture overview

```
  browser
     |
     v
  public/index.php ................ the only web-reachable PHP file
     |
     v
  bootstrap.php ................... autoloader, .env, config, container, routes
     |
     v
  Kernel::handle(Request)
     |
     +-> Router::match() .......... path + method -> Route + parameters
     |
     +-> Pipeline ................. global middleware, then route middleware
     |      SecurityHeaders -> StartSession -> TrackLastSeen
     |        -> [VerifyCsrf, Authenticate, Authorize:permission]
     |
     +-> Controller ............... constructor-injected, returns a Response
     |      |
     |      +-> Service ........... Auth, Gate, AccountService
     |             |
     |             +-> Repository . all SQL lives here
     |                    |
     |                    +-> Db .. PDO, prepared statements only
     |
     +-> View ..................... plain PHP templates -> HTML string
     |
     v
  Response::send() ................ status, headers, body
```

`bootstrap.php` returns a `Kernel` and is the single boot path: `public/index.php`,
`cli/console.php` and the test suite all go through it, so they cannot drift
apart. Anything thrown out of a controller is caught by the Kernel: an
`HttpException` becomes its status code, anything else is logged and becomes a
500. With `APP_DEBUG=true` the 500 page shows the exception and its trace.

## Directory layout

```
app/
  Core/            the framework: Kernel, Router, Route, Request, Response,
                   Container, Pipeline, View, Session, Csrf, Validator, Db,
                   Migrator, Mailer, Config, Env, Logger, RateLimiter,
                   Translator, Storage, Autoloader, HttpException
  Controllers/
    Web/           returns HTML (home, profile, settings, books, categories,
                   tags, requests, collections, uploads, downloads, covers,
                   the reader, submissions)
    Auth/          register, login, password reset, email verification
    Librarian/     the moderation queue, the catalogue list, the taxonomy screen
    Admin/         the admin area: dashboard, settings, audit, storage,
                   takedowns, librarian applications, users
    Api/           returns JSON and XML: health, the public catalogue, the
                   RSS and OPDS feeds
    Controller.php base class: render, redirect, flash, backWithErrors
  Middleware/      SecurityHeaders, StartSession, SetLocale, ViewContext,
                   TrackLastSeen, MaintenanceMode, RateLimit, VerifyCsrf,
                   Authenticate, RedirectIfAuthenticated, Authorize
  Models/          typed rows, built only by their repositories: User, Book,
                   Author, Category, Tag, BookFile, ModerationRequest,
                   BookRequest, Collection
  Repositories/    all SQL: User, Permission, AuthToken, LoginAttempt, AuditLog,
                   Settings, Book, Author, Publisher, Category, Tag, BookFile,
                   Moderation, Notification, BookRequest, Collection, Reading,
                   Review, Reputation, Badge, Takedown, Application, Statistics
  Services/        Auth (who is signed in), Gate (what they may do),
                   AccountService (registration, verification, resets),
                   BookService (create and edit a record), TaxonomyService,
                   UploadPipeline (bytes in), ModerationService (the queue),
                   BookRequestService, CollectionService, CoverGenerator,
                   TextExtractor (words out of a file, for search inside),
                   ReviewService, ReputationService, NotificationService
  Support/         not framework, not persistence: Timestamp, Role, UserStatus, Password,
                   AuthResult, ContentType, BookStatus, Licence, Slug,
                   ModerationStatus, ModerationType, RejectionReason,
                   RequestStatus, Visibility, ReputationAction, UploadResult,
                   SystemStatus
  Views/
    layouts/       the page shell
    partials/      header, footer, flash banners, field errors
    pages/         one file per page, with auth/ and admin/ subdirectories
    errors/        404 and the generic error page
cli/console.php    command line entry point
config/            app, database, storage, mail
database/
  migrations/      NNNN_name.sql, applied in filename order
  seeds/books.php  public domain records for `db:seed`
resources/lang/    one file per language, keyed by the English string
public/            webroot: index.php, .htaccess, assets
  assets/img/logo-mark.png   the ADK DEV mark, used as favicon and in the header
routes/            web.php and api.php, each returning a closure over the Router
storage/           never web-reachable: library, quarantine, covers, cache,
                   logs, backups
tests/             Unit and Feature, plus TestCase and the PHPUnit bootstrap
bootstrap.php      builds and returns the Kernel
```

`tests/Doubles` holds the fake middleware the pipeline tests need; test support
classes live there rather than inside a test file, because PSR-1 wants one class
per file and phpcs enforces it.

## Routing

Routes are declared in `routes/web.php` and `routes/api.php`. Each file returns a
closure that takes the `Router`:

```php
return static function (Router $router): void {
    $router->group(['middleware' => [VerifyCsrf::class]], static function (Router $router): void {
        $router->get('/', [HomeController::class, 'index'])->name('home');
    });
};
```

Placeholders, compiled by `Route::pattern()`:

| Syntax | Matches | Example |
|---|---|---|
| `{slug}` | one path segment | `/books/{slug}` |
| `{path...}` | the rest of the path, slashes included | `/categories/{path...}` |
| `{id:[0-9]+}` | one segment against an explicit regex | `/requests/{id:[0-9]+}` |

Matching is first-registered-wins, so a `{path...}` route has to be declared
after any specific route under the same prefix. `Request::path()` strips a
trailing slash before matching. A path that matches no route is a 404; one that
matches but with the wrong method is a 405 listing the methods that would work.

Named routes generate URLs with `$router->url('books.show', ['slug' => 'dune'])`,
and in a template with `$this->url(...)`. A missing parameter throws rather than
producing a broken link.

Current routes:

| Method | Path | Middleware | Handler |
|---|---|---|---|
| GET | `/` | csrf | `Web\HomeController@index` |
| GET | `/u/{username}` | csrf | `Web\ProfileController@show` |
| GET | `/books` and `/search` | csrf | `Web\BookController@index` |
| GET | `/books/{slug}` | csrf | `Web\BookController@show` |
| GET, POST | `/books/new`, `/books` | csrf, auth, `book.upload` | `Web\BookFormController` |
| GET, POST | `/books/{slug}/edit` | csrf, auth, `book.upload` | `Web\BookFormController` |
| POST | `/books/{slug}/status` | csrf, auth, `book.publish` | `Web\BookFormController@status` |
| GET | `/categories` | csrf | `Web\CategoryController@index` |
| POST | `/categories/propose` | csrf, auth, `taxonomy.propose` | `Librarian\TaxonomyController@storeCategory` |
| GET | `/categories/{path...}` | csrf | `Web\CategoryController@show` |
| GET | `/tags`, `/tags/{slug}` | csrf | `Web\TagController` |
| GET | `/collections` | csrf | `Web\CollectionController@index` |
| GET | `/collections/{path...}` | csrf | `Web\CollectionController@show` |
| POST | `/collections` | csrf, auth, `collection.create.private` | `Web\CollectionController@store` |
| POST | `/collections/add` | csrf, auth, `collection.create.private` | `Web\CollectionController@addFromBook` |
| POST | `/collections/{id}` | csrf, auth | `Web\CollectionController@act` (child, rename, visibility, publish, follow, fork, delete, add-book, remove-book, move-book, maintainers) |
| GET | `/requests` | csrf | `Web\RequestController@index` |
| GET | `/requests/{id}` | csrf | `Web\RequestController@show` |
| POST | `/requests` | csrf, auth, `request.create` | `Web\RequestController@store` |
| POST | `/requests/{id}/vote` | csrf, auth, `request.vote` | `Web\RequestController@vote` |
| POST | `/requests/{id}` | csrf, auth | `Web\RequestController@act` (claim, release, close, reopen, fulfil) |
| POST | `/books/{slug}/files` | csrf, auth, `book.upload` | `Web\UploadController@store` |
| GET | `/files/{id}` | csrf, auth, `book.download` | `Web\FileController@show` |
| GET | `/covers/{id}` | csrf | `Web\CoverController@show` |
| GET | `/contributors` | csrf | `Web\ContributorController@index` |
| GET, POST | `/report` | csrf | `Web\ReportController` (no account needed) |
| GET, POST | `/apply` | csrf, auth | `Web\ApplyController` |
| GET | `/admin` | csrf, auth, `user.manage` | `Admin\DashboardController@index` |
| GET, POST | `/admin/settings` | csrf, auth, `user.manage` | `Admin\SettingsController` |
| GET | `/admin/audit`, `/admin/audit.csv` | csrf, auth, `user.manage` | `Admin\AuditController` |
| GET | `/admin/storage` | csrf, auth, `user.manage` | `Admin\StorageController@index` |
| GET, POST | `/admin/takedowns` | csrf, auth, `user.manage` | `Admin\TakedownController` |
| GET, POST | `/admin/applications` | csrf, auth, `user.manage` | `Admin\ApplicationController` |
| POST | `/books/{slug}/reviews` | csrf, auth, `review.write` | `Web\ReviewController@store` |
| POST | `/reviews/{id}/delete` | csrf, auth | `Web\ReviewController@destroy` |
| POST | `/reviews/{id}/helpful` | csrf, auth, `review.write` | `Web\ReviewController@vote` |
| POST | `/reviews/{id}/moderate` | csrf, auth, `review.moderate` | `Web\ReviewController@moderate` |
| GET | `/books/{slug}/read/{file}` | csrf, auth, `book.read` | `Web\ReaderController@show` |
| POST | `/books/{slug}/read/{file}/progress` | csrf, auth, `book.read` | `Web\ReaderController@saveProgress` |
| POST | `/books/{slug}/read/{file}/bookmarks` | csrf, auth, `book.read` | `Web\ReaderController@addBookmark` |
| POST | `/books/{slug}/read/{file}/bookmarks/{bookmark}/delete` | csrf, auth, `book.read` | `Web\ReaderController@deleteBookmark` |
| GET | `/me/submissions` | csrf, auth | `Web\SubmissionController@index` |
| GET, POST | `/me/submissions/{id}` | csrf, auth | `Web\SubmissionController` |
| GET | `/me/notifications` | csrf, auth | `Web\SubmissionController@notifications` |
| GET | `/librarian/queue` | csrf, auth, `moderation.queue` | `Librarian\QueueController@index` |
| GET | `/librarian/queue/{id}` | csrf, auth, `moderation.queue` | `Librarian\QueueController@show` |
| POST | `/librarian/queue/{id}/claim` | csrf, auth, `moderation.queue` | `Librarian\QueueController@claim` |
| POST | `/librarian/queue/{id}/release` | csrf, auth, `moderation.queue` | `Librarian\QueueController@release` |
| POST | `/librarian/queue/{id}/decide` | csrf, auth, `moderation.queue` | `Librarian\QueueController@decide` |
| POST | `/librarian/queue/{id}/comment` | csrf, auth, `moderation.queue` | `Librarian\QueueController@comment` |
| GET | `/librarian/books` | csrf, auth, `taxonomy.manage` | `Librarian\CatalogueController@index` |
| GET | `/librarian/taxonomy` | csrf, auth, `taxonomy.manage` | `Librarian\TaxonomyController@index` |
| POST | `/librarian/categories` | csrf, auth, `taxonomy.manage` | `Librarian\TaxonomyController@storeCategory` |
| POST | `/librarian/categories/{id}/decide` | csrf, auth, `taxonomy.manage` | `Librarian\TaxonomyController@decideCategory` |
| POST | `/librarian/tags/{id}/decide` | csrf, auth, `taxonomy.manage` | `Librarian\TaxonomyController@decideTag` |
| POST | `/librarian/tags/{id}/alias` | csrf, auth, `taxonomy.manage` | `Librarian\TaxonomyController@storeAlias` |
| GET, POST | `/register` | csrf, guest | `Auth\RegisterController` |
| GET, POST | `/login` | csrf, guest | `Auth\LoginController` |
| GET, POST | `/forgot-password` | csrf, guest | `Auth\PasswordResetController` |
| GET, POST | `/reset-password/{token}` | csrf, guest | `Auth\PasswordResetController` |
| GET | `/verify-email/{token}` | csrf | `Auth\EmailVerificationController@consume` |
| GET | `/verify-email` | csrf, auth | `Auth\EmailVerificationController@notice` |
| POST | `/verify-email/resend` | csrf, auth | `Auth\EmailVerificationController@resend` |
| POST | `/logout` | csrf, auth | `Auth\LoginController@destroy` |
| GET, POST | `/me/settings` | csrf, auth | `Web\SettingsController` |
| POST | `/me/password` | csrf, auth | `Web\SettingsController@password` |
| GET | `/admin/users` | csrf, auth, `user.manage` | `Admin\UserController@index` |
| POST | `/admin/users/{id}/role` | csrf, auth, `user.manage` | `Admin\UserController@updateRole` |
| POST | `/admin/users/{id}/status` | csrf, auth, `user.manage` | `Admin\UserController@updateStatus` |
| GET | `/health` | none | `Api\HealthController@show` |
| GET | `/api/v1/health` | none | `Api\HealthController@show` |

`php cli/console.php route:list` prints this from the router itself.

Middleware can take arguments after a colon, which is how a route declares the
permission it needs:

```php
$router->group([
    'prefix'     => '/admin',
    'middleware' => [Authenticate::class, Authorize::class . ':user.manage'],
], ...);
```

`Pipeline::parse()` splits the declaration and passes the arguments to
`handle(Request $request, Closure $next, string ...$arguments)`.

## Controllers and the container

A controller is a plain class whose constructor asks for what it needs. The
container resolves the type hints by reflection, so nothing needs registering
unless it takes scalars or has to be a singleton (those are wired in
`bootstrap.php`).

```php
final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly Db $db,
        private readonly SystemStatus $status,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('pages/home', [...]));
    }
}
```

Every action takes a `Request` and must return a `Response`; returning anything
else throws. `Response` has `html()`, `json()`, `redirect()` and `noContent()`
constructors, and `withHeader()` for anything else.

Middleware implements `App\Core\Middleware`: `handle(Request $request, Closure
$next): Response`. Return `$next($request)` to continue or your own `Response` to
stop. Global middleware is listed at the bottom of `bootstrap.php`; per-route
middleware goes on the route or the group.

## Views and theming

`View::render()` runs a plain PHP file with the data extracted into scope and
returns the HTML as a string. Page data must not use the names `__path` or `__data`, which the renderer uses
for its own locals (see the gotchas). A template opts into the layout and fills
slots:

```php
$this->layout('layouts/app');
$this->section('title');
echo $this->e($book->title);
$this->end();
```

The layout reads them back with `$this->slot('title', 'default')` and the page
body arrives as `$this->slot('content')`. Other helpers available inside a
template: `$this->e()` (htmlspecialchars, use it on everything), `$this->include()`,
`$this->url()`, `$this->asset()` (appends `?v=filemtime` for cache busting) and
`$this->config()`.

Theming is CSS custom properties in `public/assets/css/app.css`. The light
palette is defined on bare `:root`; dark redefines only the tokens, twice, once
under `@media (prefers-color-scheme: dark)` guarded with
`:root:not([data-theme="light"])` and once under `:root[data-theme="dark"]`, so
the toggle wins in both directions. `public/assets/js/theme.js` stores the choice
in `localStorage` under `dl.theme` and is loaded synchronously in `<head>` so the
attribute is set before first paint.

`View::date()` is the only way a template turns a stored timestamp into words.
Rows are UTC and the application timezone usually is not, so a bare
`date('j M Y', strtotime($row))` reads hours away from the truth; see
[Timestamps](#timestamps).

The ADK DEV mark is a single purple-on-transparent PNG used for the favicon and
the header badge. It is recoloured per theme with `filter: brightness(0)` in
light and `brightness(0) invert(1)` in dark (`.logo-mono`), so there is only one
image file.

## The interface

One stylesheet, `public/assets/css/app.css`, organised as tokens, base, layout,
components. There is no framework and no build step: a contributor edits the
file and reloads.

**The page shells.** Every page is one of four shapes, so nothing is a lone
column of text with empty space beside it:

| Shape | Class | Used by |
|---|---|---|
| Full width | `.stack-wide` | lists, tables, admin screens |
| Main and aside | `.page-body` + `.page-main` + `.page-aside` | profile, review, anything with side facts |
| Filters and results | `.browse__layout` + `.browse__facets` + `.browse__results` | browse, search, a collection |
| Centred card | `.form-page` | sign in, register, report, apply |

`.page-body` collapses to one column under 60rem, and also when the aside is
empty (`:not(:has(.page-aside > *))`), so a page with nothing in the sidebar
still fills the width.

**Links.** Two kinds, and every link is one of them. Prose links (a sentence, a
footer line, a review) keep the accent colour and an underline. Structural links
(navigation, breadcrumbs, trees, cards, table rows) take the text colour with no
underline and pick both up on hover. A link that matches no rule falls back to
the prose style, so a new link is never unstyled.

**The header** is sticky and deliberately short: the brand, **Browse**, the
search box, and then the right hand group (add a book, the theme toggle, alerts,
the account menu, and the overflow menu). Everything else, categories,
collections, requests, tags, people and the report form, lives behind the
overflow menu at the right hand end. Browse is what a visitor came for; the rest
is where to go next.

Under 76rem the search box is dropped (browse has its own), and under 52rem
Browse joins the overflow menu, so the bar becomes the brand and one button.
`public/assets/js/menu.js` only adds what `<details>` cannot do on its own:
close on an outside click and on Escape.

Icons are inline SVG with `stroke: currentColor`, not an icon font and not
emoji, so they take the text colour and stay crisp. The theme button shows the
theme it would switch to, and which of its two icons is visible is decided in
CSS from `data-theme` and `prefers-color-scheme`; the script only keeps the
`aria-label` honest.

**Browse** puts the filters across the top (search, kind, tag, language, sort)
and keeps only the category tree in the sidebar. Filters are one form, so
changing two of them is one round trip, and the active ones appear underneath as
chips that remove themselves when clicked.

**The footer** is four columns (the library and its tagline, Explore, Take part,
Language) over a base line. It carries no version number and no PHP version: a
visitor cannot act on either, and a build is not something a library publishes.

**Components** worth knowing before adding a page: `.panel` (a titled card),
`.empty` (nothing here yet, said in a way that does not look broken),
`.button` with `--small`, `--quiet` and `--danger`, `.tag`, `.pill`, `.banner`
with `--ok`, `--warn` and `--error`, `.table` inside `.table-scroll`,
`.section-head` (a heading with a link on the right), `.subnav` (the admin
sections), `.staff-tools` (a `<details>` panel for controls only staff see) and
`.page-message` (an error page or the closed sign).

## Timestamps

Every datetime in the database is UTC, and `app.timezone` is usually not, so
parsing a stored value with plain `strtotime()` lands hours away: that bug made
a live 30 minute review claim look expired the moment it was taken, which hid
the approve and reject buttons.

`App\Support\Timestamp` is the only correct way to read one:

| Call | Gives |
|---|---|
| `Timestamp::epoch($value)` | seconds since the epoch |
| `Timestamp::format($value, 'j M Y')` | the date in the application timezone |
| `Timestamp::since($value)` | seconds elapsed |
| `Timestamp::daysSince($value)` | whole days elapsed |
| `Timestamp::isPast($value)` | whether the moment has passed |

Templates reach it through `$this->date($timestamp, $format)`.
`tests/Unit/TimestampTest.php` runs the same assertions under four timezones so
the bug cannot come back.

## Auth model

**A session holds a user id and nothing else.** `Auth::user()` loads the row on
every request, so a role change, a ban or a deletion takes effect on the affected
user's very next request rather than whenever their cookie expires. The cost is
one indexed primary key lookup per request.

- **Hashing** is Argon2id where the build has it, bcrypt otherwise
  (`Support\Password`). A hash made by the weaker algorithm is upgraded silently
  on the next successful sign in.
- **Session fixation**: `Session::regenerate()` runs on sign in and on sign out.
- **Throttling**: five failed attempts for one email address *or* one IP within
  15 minutes and the sixth is refused before the password is checked
  (`login_attempts`). A successful sign in clears the counter. IPs are stored as
  an HMAC keyed on `APP_KEY`, so the table counts attempts without being a record
  of who was where.
- **Email confirmation and password resets** share `auth_tokens`. Only the
  SHA-256 of a token is stored, so the table cannot hand out working links.
  Issuing a token invalidates the previous ones of its type. Confirmation links
  last two days, reset links one hour and one use.
- **A forgotten-password request always reports success**, whether or not the
  address has an account. Telling a stranger which addresses are registered is a
  disclosure the flow does not need.
- **The first account on an empty install becomes a verified admin.** An install
  with no admin can never promote anyone. Every later account starts as an
  unconfirmed member.
- **Status**: `active` is normal, `muted` can read but not contribute, `banned`
  cannot sign in at all. A mute can carry an expiry, which is lifted on the next
  sign in attempt. `User::canContribute()` is status plus a confirmed email.

Routes declare their needs with middleware: `Authenticate` for a session,
`RedirectIfAuthenticated` for the signed-out-only pages, and `Authorize:key` for
a permission.

## Permissions

30 permission keys live in the `permissions` table, seeded by migration 0003 from
the matrix in PLAN.md section 3. `role_permissions` maps them onto the three
roles; `user_permissions` overrides that for one person in either direction
(`grant` adds a key, `revoke` removes one) so an admin can lend out a single
capability without promoting anyone.

`Services\Gate` resolves them:

```php
$gate->allows('book.upload');     // bool
$gate->denies('moderation.queue');
$gate->authorize('user.manage');  // throws 401 signed out, 403 signed in
$gate->canContribute();           // status is active and the email is confirmed
```

A guest gets exactly one key, `catalog.browse`, from a constant in `Gate` rather
than the database: it is the entire public surface of the site, and it should be
visible in the code that enforces it.

Rough shape of the three roles: a member may read, request and contribute
(everything they submit is reviewed); a librarian adds the moderation keys and
`book.publish`; an admin has every key including `user.manage`,
`librarian.approve` and `settings.manage`.

## The catalogue

### Books and their parts

A `book` is the bibliographic record. A `book_file` is a downloadable artefact
attached to it, so one book carries a scanned PDF, a clean PDF and an EPUB under
one set of metadata. Only the upload pipeline writes to `book_files`; a
controller never inserts one directly.

`BookRepository` hydrates lists in batches: one query for the books, then one
each for authors, categories, tags and files across all of them. A page of 24
books is five queries, not ninety-seven.

`BookService` is the only thing that creates or edits a record. It decides the
status from the actor's permissions (`book.publish` publishes, anything else
queues), resolves author and tag names to rows, and writes the audit entry.

**A published record's address stops following its title.** Renaming a draft
regenerates the slug; renaming something already public does not, because the
slug is in every link that already exists to it.

### Search

Four clauses ORed together: MySQL FULLTEXT in boolean mode over `title`,
`subtitle` and `description`, a `LIKE` on the title, an `EXISTS` against the
author names, and an `EXISTS` against `book_texts`, which is the text inside the
book itself. Each one is there for a reason:

- FULLTEXT gives relevance ranking and prefix matching, but ignores words shorter
  than `innodb_ft_min_token_size` (three characters by default), so "Ox" or "AI"
  would find nothing on their own.
- `BookRepository::booleanQuery()` strips the boolean operators a person might
  type (`+ - > < ( ) ~ * " @`) and appends `*` to each remaining word. A typed
  hyphen does not silently become a negation.
- The author clause is why searching "darwin" finds *On the Origin of Species*
  even though the word appears nowhere in the record's own text.
- The `book_texts` clause is search inside books. It matches a phrase that
  appears on page 300 and nowhere in the metadata, which is the difference
  between a catalogue and a library. It is scoped to files that are published:
  the join goes through `book_id`, and a quarantined file has no book to join
  to.

`book_texts` is filled by `TextExtractor`, which the upload pipeline calls when a
file arrives and `search:reindex` calls when the index has to be rebuilt. It
stores at most 60,000 characters per book. That is a deliberate ceiling: enough
that a search finds the book, small enough that the index does not become a
second copy of the library. A PDF with no text layer contributes nothing and is
flagged as a scan on the record.

Facet counts come from the same WHERE clause as the results, minus the facet
being counted, so choosing "Comics" still shows how many books the other kinds
would give you.

`book_texts` holds extracted full text with its own FULLTEXT index. The upload
pipeline fills it, `search:reindex` rebuilds it, and search reads it (see
[Search](#search)).

### Categories

A tree with a materialised `path` (`/academics/competitive-exams/upsc/`), a
`depth` and a `parent_id`. The path is what makes it cheap:

- a subtree is `path = ? OR path LIKE ?`, one indexed prefix match
- a breadcrumb is `explode('/', $path)` plus one `IN` query, not a walk up the
  parents
- the URL is the path: `/categories/academics/competitive-exams/upsc`, routed by
  the `{path...}` wildcard

Depth is capped at `CategoryRepository::MAX_DEPTH` (8). **A category's
`book_count` is its whole subtree's count**, because that is what clicking it
shows; a parent reading 0 next to children reading 12 would be a lie about the
same query. `catalogue:recount` recalculates it, and every write that could move
a book runs it.

A member's proposed category is created with status `pending`: it exists, but
`findByPath()` and `tree()` filter it out until a librarian approves it.

### Tags

Flat, and `pending` until approved. A member's new tag attaches to their book
immediately but stays out of `/tags` and the suggestions, which is what stops the
catalogue fragmenting into sci-fi, scifi and science fiction. `tag_aliases` folds
the synonyms that arrive anyway: `/tags/sci-fi` redirects to
`/tags/science-fiction`, so there is one address per tag rather than one per
spelling. An alias cannot shadow an existing tag slug.

## Uploads and moderation

### The pipeline

`UploadPipeline::receive()` is everything between "someone chose a file" and "a
reviewer can look at it". Nothing in it trusts the browser: not the filename, not
the extension, not the declared type, not the size.

1. **Upload error** codes turned into sentences a person can act on.
2. **Size**, against `MAX_UPLOAD_BYTES` and against the uploader's remaining
   quota.
3. **Extension**, refused outright if executable (`php`, `phtml`, `phar`, `html`,
   `js`, `svg`, `exe`, …), then checked against the allowlist.
4. **Content**, sniffed with `finfo`. The detected type has to be one the
   extension is allowed to have, so a PHP script named `book.pdf` is refused.
   EPUB and CBZ are ZIP containers, so `application/zip` is expected there.
5. **Active content**: a PDF containing `/JavaScript`, `/JS`, `/Launch` or
   `/OpenAction` is refused. A reader that honours them turns a library into a
   delivery mechanism. The scan reads in overlapping chunks so a marker split
   across a boundary is still seen.
6. **Hash** (SHA-256), which is both the deduplication key and the filename.
7. **Duplicate**: `book_files.sha256` is unique, so the same bytes cannot be
   stored twice; the uploader is told which record already has them.
8. **Quarantine**: the file moves to `storage/quarantine/ab/cd/<sha>.<ext>`,
   mode 0640, reachable by nobody.
9. **Metadata**: page count and up to 60,000 characters of text from
   `smalot/pdfparser`, best effort. A PDF with no text layer is flagged as a scan
   so the reviewer knows it will not be searchable.
10. **Cover**: if the record has no cover yet and the file is a PDF, page one is
    rendered into `storage/covers/ab/cd/<sha>.jpg` and set on the book. A record
    that already has a cover keeps it.
11. **Queue**: a `book_upload` request, unless the uploader has `book.publish`,
    in which case the file is published immediately.

### Covers

PHP cannot rasterise a PDF on its own, so `CoverGenerator` uses whatever the host
has, in this order: the Imagick extension, `pdftoppm` (poppler-utils), then
Ghostscript. The result is scaled to 600px wide with GD and re-encoded as JPEG,
so every cover comes out the same shape whichever tool made it.

**A host with none of them simply gets no covers.** An upload must never fail
because a picture could not be made of it, and the book page falls back to the
tinted letter it used before. `covers:generate` says which renderer is in use and
backfills the records that have none.

Because it is a system package rather than a Composer requirement, nothing
enforces it, and that has bitten twice: the shipped `Dockerfile` installed no
renderer at all, so the official image silently never made a cover, and CI
installed none either, so the five cover tests depended on whatever the runner
image happened to ship and failed on `main`. Both now install `poppler-utils`
explicitly. **If you deploy anywhere else, install one of the three yourself**,
and remember the failure is silent by design.

The renderers are run with every argument escaped and, where `timeout` exists, a
20 second limit: a malformed PDF can send a rasteriser into a very long loop. The
only paths that reach the command line are ours (a hash under `storage/` and a
temporary file), never a filename a user chose.

Covers are served by `CoverController` at `/covers/{book}`, not linked directly:
they live under `storage/` like everything else. They are public for a published
book, since a cover is catalogue metadata rather than the book, and hidden for a
record still in review except from its submitter and a reviewer.

### Storage layout

```
storage/
  quarantine/ab/cd/<sha256>.pdf   waiting for a decision, 0640
  library/ab/cd/<sha256>.pdf      approved and downloadable
```

Two levels of sharding by the hash so no directory ends up with a hundred
thousand entries. Approving **hard links** the file into `library/` and unlinks
the quarantine name: approving a 400 MB scan should not copy 400 MB, and if the
link fails (a different filesystem) it falls back to a rename. `STORAGE_ROOT`
moves the whole tree; the tests point it at a temporary directory.

### The engine

`ModerationService` owns the state machine for every subject type. The rules live
there rather than in the controllers, so the queue screen, the taxonomy screen
and the CLI cannot disagree:

- **A reviewer cannot decide their own submission** unless they are an admin, and
  then `audit_logs.after_state.own_submission` is true.
- **Claiming is the lock.** `ModerationRepository::claim()` is one UPDATE whose
  WHERE clause refuses to overwrite a live claim, so two reviewers pressing at
  the same instant cannot both win. The claim lasts 30 minutes and then expires,
  because an abandoned review must not block an item forever.
- **A rejection needs a reason.** `RejectionReason` holds the canned list; a
  copyright rejection also adds a strike to the uploader (PLAN.md section 9).
- **Every transition writes a `moderation_events` row** and notifies the
  submitter. The submitter sees the same history the reviewer does.
- **Approval is the only thing that publishes**: the file moves out of
  quarantine, the record goes public if it was not already, the uploader is
  credited with the bytes and with reputation.

`changes_requested` is the middle path: it sends the item back to the submitter
with a note instead of refusing it, and they resubmit into the same request
rather than starting again.

Book uploads and category proposals share the queue today. Book requests (M4),
collections (M5) and librarian applications join it as they are built. Tag
proposals deliberately stay a lightweight approve list on the taxonomy screen: a
tag is one word and does not need a review thread.

### Serving files

`FileController` is the only path from `storage/` to a browser: permission first,
then the download counter, then the bytes. It honours `Range` with a 206 (and a
416 for a range past the end), streams in 256 KB chunks from a callback rather
than reading the file into memory, and sends `Content-Disposition: attachment`
unless `?inline` is passed. A quarantined file is visible only to a reviewer and
to the person who uploaded it, and reading one does not count as a download.

## Book requests

A request is the other half of the library: the catalogue says what is here, the
request list says what is missing.

- **Asking is voting.** `BookRequestService::create()` adds the requester's vote,
  because making them click again would only make the demand ordering wrong. The
  default sort is `vote_count DESC, created_at ASC`, so the list is worked in
  order of demand and ties break oldest first.
- **Claiming** marks a request `claimed` with a name against it, so two people do
  not go and scan the same book. The claimer or a librarian can release it.
- **Closing by hand** is limited to the endings a person can honestly choose:
  unavailable, duplicate, or declined. `fulfilled` is not among them; it comes
  only from a book arriving.
- **Fulfilment is a side effect of approval, not a second chore.** A submission
  can carry a `request_id` (the form arrives at `/books/new?request=N`, prefilled
  from the request). If the submitter can publish, the request is answered at
  once; otherwise the id rides along in the moderation payload and the approval
  answers it. Everyone who voted is notified, and the fulfiller gains reputation.
- **A record submitted with no file is queued as `book_record`.** Without that a
  member's metadata-only contribution would sit in `pending` with nothing
  pointing a reviewer at it. Approving publishes it (and answers any linked
  request); rejecting marks the record `rejected` rather than leaving it to rot.

## Collections

The folders anyone can build, to any depth. Same materialised `path` as
categories, so a node's address is its path and a subtree is one indexed prefix
match, plus a `root_id` on every node pointing at the top of its own tree, which
makes "publish this whole collection", "who follows it" and "copy it" single
queries instead of walks.

### Two axes, kept apart

`visibility` is who can see it now: private, unlisted, public. `review_status` is
how far its request to be published has got: none, pending, approved, rejected.
Conflating them into one status made the private case wrong, because "private"
is not a stage on the way to "public": it is a perfectly good end state.

- **Private and unlisted need nobody's permission.** They are one person's shelf
  and one person's link, and they never enter the queue. A private collection
  404s for everyone but its owner, its maintainers and anyone with
  `collection.approve`; it is not there rather than forbidden.
- **Public is the only one that is reviewed**, because it is the only one that
  puts something in front of everybody. Publishing marks the whole tree
  `pending` and opens a `collection_publish` queue item; approval sets the whole
  tree public in one UPDATE.
- **A collection is published whole**, not folder by folder, so the reviewer
  decides on the thing people will actually see.

### Curating

`CollectionService` owns the rules, because the same questions are asked from the
node page, the book page and the approval path. Editing is the owner or an
invited maintainer, and a librarian does not get to edit someone's shelf: their
power is to approve or refuse publication.

Reordering is up and down buttons rather than drag and drop, which would need
JavaScript the CSP would have to allow, for an ordering people set once.

`item_count` on a node counts its whole subtree, the same rule as categories: it
is what clicking the node shows.

**Forking** copies a public collection into the forker's own private tree,
folders and books and all, ordered by depth so a node's parent is always copied
before it. **Following** notifies everyone but the person who added the book.

Smart nodes (a saved search mounted as a folder) are in PLAN.md but not built;
there are no columns for them yet.

## Reading

The reader is one page (`pages/books/read.php`) and one script
(`public/assets/js/reader.js`). Everything the script needs is on the
`[data-reader]` element: which kind of file, where to fetch it, where the reader
got to last time, and a CSRF token. No server-rendered JavaScript, because the
CSP forbids inline script.

### The libraries are vendored

`public/assets/vendor` holds PDF.js, epub.js and JSZip, committed to the
repository. The CSP allows `script-src 'self'` and the project has no build
step, so a CDN is not an option and neither is npm at deploy time. Two megabytes
in git is the price of not weakening the CSP for everyone. `vendor/README.md`
records the versions and licences; updating one is replacing a file.

### What each format does

| Format | Renderer | `position` means |
|---|---|---|
| PDF | PDF.js into a canvas, one page at a time | the page number |
| EPUB | epub.js into an iframe | an EPUB CFI |
| TXT | fetched and put in a `<pre>` | percent scrolled |

Anything else (MOBI, DJVU, CBZ, audio, video) is a download; the reader page says
so rather than showing a spinner over a blank area. `BookFile::isReadable()` is
the one place that decides.

`position` is a string in the database for that reason: only the reader that
wrote it has to understand it, and `percent` is the part the rest of the app uses
(the "carry on reading" list on the home page, for instance).

### Progress and bookmarks

Progress is saved by `fetch` to `/books/{slug}/read/{file}/progress` with the
CSRF token in an `X-CSRF-Token` header, at most once every four seconds and again
on `pagehide` with `keepalive`. A page turn is not a request.

`reading_progress` is unique on (user, file) and written with an upsert, so
reading a book twice does not accumulate rows. Bookmarks belong to one person:
the delete is `WHERE id = ? AND user_id = ?`, so knowing another person's
bookmark id does not help.

The reader endpoints refuse a file that is not published on a book that is not
public, unless the reader is a reviewer or the person who uploaded it, and they
check that the file actually belongs to the book in the address: a file id from
one book cannot be opened under another book's slug.

## Reviews and reputation

### Reviews

One review per person per book, enforced by a unique key rather than a check in
the service. A review is a rating from one to five and, optionally, something to
say; posting again edits the one you have.

`books.rating_average` and `rating_count` are denormalised and recalculated by
`ReviewRepository::refreshBookRating()` after anything that could change them,
because every card in a listing shows them and none of them should need a join.

**Hidden is not deleted.** A moderator with `review.moderate` can hide a review,
with a reason; the person who wrote it still sees it on the page, and hears about
it, so they know what happened rather than wondering where it went. A hidden
review leaves the average: something nobody else can read should not be moving
the number.

You cannot mark your own review helpful.

### Reputation

`users.reputation` is a running total and `reputation_events` is what it is made
of. Keeping the events is what lets someone ask why they have the points they
have, and what gives badges something to count.

Everything that pays goes through `ReputationService::award()`, and the scale
lives in one enum:

| Action | Points |
|---|---|
| `upload.accepted` | 5 |
| `request.fulfilled` | 10 |
| `review.written` | 2 |
| `review.helpful` | 1 |
| `collection.published` | 8 |
| `moderation.decided` | 1 |

**Naming a subject makes an award idempotent.** `award($user, UploadAccepted,
'book_file', 12)` pays once however many times it is called, which matters
because approval paths can be re-entered. An award with no subject (a helpful
vote from a new person, say) counts every time, so the caller passes something
that identifies the pair: `'review_vote:' . $voterId` with the review id.

`revoke()` writes a negative event rather than deleting the original: taking a
review back should undo the points without erasing the history.

### Badges

A badge is a row: a name, an action and a threshold. `awardBadgesFor()` counts
the person's events of that action and awards anything they have passed, once,
with a notification. Adding a badge is an INSERT in a migration, not code.

## Running the library

### Settings

`settings` holds JSON values read through `SettingsRepository`, which caches the
table for the request. `Admin\SettingsController` declares each editable field
once, with its type, so the form, the cast and the write cannot disagree; adding
a setting is a row in that list plus a default in a migration.

**A setting is read where it is used, not at boot**, so a change takes effect on
the next request without a deploy or a restart:

| Key | What reads it |
|---|---|
| `site.name`, `site.tagline` | the header and the home page |
| `registration.mode` | `RegisterController`, which still lets the first account through |
| `uploads.max_bytes` | `UploadPipeline`, over the environment default |
| `uploads.default_quota` | `AccountService` when it creates an account |
| `uploads.require_licence_evidence` | the review screen |
| `features.reviews`, `features.requests` | the templates that would show them |
| `site.maintenance`, `site.maintenance_message` | `MaintenanceMode` |

Every change is written to the audit log with the previous value.

### Maintenance mode

`MaintenanceMode` runs after the session middleware and returns a 503 with a
`Retry-After` for everyone but an admin. `/health` and `/api/` are exempt: a
monitor that cannot tell "closed for an hour" from "down" is not much of a
monitor. `/login` is exempt too, or an admin locked out of a closed site could
never turn it back on.

### The audit log

`AuditLogRepository::paginate()` filters by actor, action prefix and subject
type; `export()` walks the same filter in pages of 200 and hands the rows to a
CSV. Nothing in the application updates or deletes an audit row.

### Takedowns

Anyone may send a notice at `/report`, with or without an account: a rights
holder should not have to join a library to ask it to stop hosting their book.
The admin console upholds or rejects it, and upholding hides the book at once
through `BookService::setStatus()`. The notice, the reason and who decided it
stay on the record either way, because an operator has to be able to show what
they did and when. See PLAN.md section 9.

### Librarian applications

A member with `librarian.apply` writes a statement; an admin approves it, which
changes the role, notifies them and writes the audit entry. These have their own
screen rather than a place in the moderation queue, because every librarian can
see the queue and only an admin may decide who joins them.

### The dashboard

`StatisticsRepository` is aggregate queries against the tables that already
exist: totals, a per-day count for the last N days with the empty days filled
in, the busiest categories, the most downloaded books, and queue health (median
time to a decision, how many have waited over a week). There is no analytics
pipeline and there does not need to be one until a library is large enough for
these to hurt.

## The public API and feeds

`routes/api.php` holds every machine-readable route: no session cookie is
required, no CSRF token is checked, and nothing writes. Everything it returns is
what a guest can already read on the website, so there is no token to issue and
nothing an API key would protect. If a route ever needs an account behind it, it
does not belong in this file.

| Route | Returns |
|---|---|
| `GET /api/v1/health` | `SystemStatus::report()`, 200 when healthy and 503 when not |
| `GET /api/v1/books` | paginated books, honouring the same filters as `/books` |
| `GET /api/v1/books/{slug}` | one book with authors, categories, tags and files |
| `GET /api/v1/categories` | the category tree, flattened, with subtree counts |
| `GET /api/v1/tags` | approved tags with their book counts |
| `GET /feed.rss` | the twenty newest books, RSS 2.0 |
| `GET /opds` | the same, as an OPDS 1.2 acquisition feed |

`CatalogueController` reuses `BookRepository` and the same filter parsing as the
HTML listing, so a query string that works on `/books` works on `/api/v1/books`.
Only published books are ever visible; the repository's visibility clause is not
optional and there is no parameter that turns it off.

Both feeds are assembled as strings, and every interpolated value goes through
`FeedController::xml()`, which is `htmlspecialchars` with `ENT_XML1`. A title
containing an ampersand is a matter of when, not if, and an unescaped one breaks
the whole document rather than one entry. OPDS is what makes the
library appear as a browsable catalogue inside an e-reader app: it is Atom with
`acquisition` link relations pointing at the same download route the website
uses, so a download still goes through the permission check and still counts.

## Rate limiting

`App\Middleware\RateLimit` is declared on a route as
`App\Middleware\RateLimit:120` for a hundred and twenty a minute. Signed-in
callers are counted by account, everyone else by a hash of the IP, so one office
behind one address does not lock out the rest of it. Over the limit is a 429 with
`Retry-After`; under it, every response carries `X-RateLimit-Limit` and
`X-RateLimit-Remaining`.

The counters are files under `storage/cache`, one per caller per window, guarded
by `flock`. There is no table: a limiter that writes to the database on every
request costs more than the requests it is protecting, and losing the counters on
a restart is harmless. **It fails open.** If the counter file cannot be opened,
the request is allowed through, because a disk problem should not take the
catalogue offline.

## Translations

`App\Core\Translator` reads `resources/lang/<locale>.php`, a flat array. **The
key is the English string**, not a dotted path:

```php
<?php echo $this->t('Browse the library'); ?>
```

A missing translation renders as readable English instead of `nav.browse`, and a
template nobody has touched yet still says something sensible. `View::t()` is the
only way a template reaches the translator.

`SetLocale` middleware picks the language: `?lang=hi` sets it, the session
remembers it, and the site default applies otherwise. `Accept-Language` is
deliberately ignored, because guessing from the browser and being wrong is worse
than being predictable when the switcher is one click away in the footer. The
same middleware shares `localeLinks` with the view: one switcher link per
language pointing at the current path with the current query string, so changing
language halfway through a filtered search keeps the search.

**Scope: the interface chrome, not the content.** `en` and `hi` cover navigation,
buttons, form labels, validation messages and flash messages. Book titles,
descriptions and reviews are shown as they were written, because a library's
catalogue is not the sort of thing a translation table should be rewriting. A
string only becomes translatable once it has been moved through `t()`, so adding
a language means adding a file, but making a page translatable means editing that
page.

## Accessibility

Not a separate feature so much as a set of rules the templates follow, with
`tests/Feature/AccessibilityTest.php` there to keep them followed:

- every page declares its language and starts with a skip link to `#main`
- exactly one `<h1>` per page
- every `<img>` has `alt`; decorative images use `alt=""`
- every form control has a label, an `aria-label` or an `aria-labelledby`
- a rejected field gets `aria-invalid="true"` and `aria-describedby` pointing at
  the message, which `View::errorAttributes()` emits so the nine forms cannot
  drift apart
- flash messages and the reader's position indicator are `role="status"` with
  `aria-live="polite"`, so a change is announced without stealing focus
- the current navigation item carries `aria-current="page"`
- data tables have `<th scope="col">`

## Data access

`App\Core\Db` wraps PDO with `ERRMODE_EXCEPTION`, `FETCH_ASSOC` and emulated
prepares off. It exposes `select()`, `first()`, `scalar()`, `execute()`,
`insert()`, `run()` and `transaction()`. Every one of them takes bindings; there
is no method that accepts an interpolated value and there should never be one.

The connection is opened lazily on first query, so a page can render with the
database down. `isConnected()` reports that without throwing, which is what the
status panel and `/health` use. Do not copy that pattern into a controller: only
the health check is allowed to swallow a connection error.

**Timezone convention: everything in the database is UTC.** `Db::pdo()` issues
`SET time_zone = '+00:00'` on connect, so `CURRENT_TIMESTAMP` and any datetime
comparison are UTC regardless of the server. Conversion to `config('app.timezone')`
happens on the way out, in the view.

From M1, SQL lives in `app/Repositories` and nowhere else.

## Data model

Thirty-seven tables. Every timestamp is UTC (see the note in
[Data access](#data-access)).

**`users`** - `id`, `name`, `username` (unique, lowercase), `email` (unique,
lowercase), `password_hash`, `role` enum(member, librarian, admin), `status`
enum(active, muted, banned), `bio`, `avatar_path`, `reputation`, `storage_used`,
`storage_quota` (2 GB default), `email_verified_at`, `status_reason`,
`status_until`, `last_seen_at`, timestamps, `deleted_at`. Soft deleted rows are
excluded from every query in `UserRepository`.

**`permissions`** - `key` (unique), `description`, `area`. Seeded, not written at
runtime.

**`role_permissions`** - (`role`, `permission_id`), the role defaults.

**`user_permissions`** - (`user_id`, `permission_id`), `effect` enum(grant,
revoke), `granted_by`. The per-person override.

**`auth_tokens`** - `user_id`, `type` enum(email_verification, password_reset),
`token_hash` (unique, SHA-256 of the token in the link), `expires_at`,
`used_at`.

**`login_attempts`** - `identifier` (the email tried), `ip_hash`, `successful`,
`created_at`. Read by the throttle, pruned by `auth:prune`.

**`audit_logs`** - `actor_id` (null when the console did it), `action`,
`subject_type`, `subject_id`, `before_state` JSON, `after_state` JSON, `ip_hash`,
`user_agent`. Append only; the viewer arrives with M8. Actions recorded today:
`account.registered`, `account.email_verified`, `account.password_reset`,
`account.password_changed`, `auth.sign_in`, `user.role_changed`,
`user.status_changed`.

**`settings`** - `key` (unique), `value` JSON, `group`, `description`. Read
through `SettingsRepository`, which decodes the JSON and caches the table for the
request.

### The catalogue tables

**`books`** - `title`, `subtitle`, `slug` (unique), `publisher_id`,
`published_year`, `edition`, `language` (ISO code), `isbn10`, `isbn13`,
`description`, `content_type` enum(book, magazine, comic, academic_paper, notes,
audiobook, video), `licence` enum(public_domain, cc_by, cc_by_sa, cc_other,
author_permission, own_work, unknown), `licence_note`, `source_url`,
`cover_path`, `page_count`, `status` enum(draft, pending, published, rejected,
hidden), `added_by`, `published_at`, `view_count`, `download_count`, timestamps,
`deleted_at`. FULLTEXT over title, subtitle and description.

**`authors`** / **`publishers`** - `name`, `slug` (unique). Created on demand
from the form by `AuthorRepository::findOrCreate()`.

**`book_authors`** - (`book_id`, `author_id`, `role`), `position`. The role is
author, editor, translator or illustrator, and it is part of the key so one
person can be both translator and editor of the same book.

**`book_files`** - `book_id`, `format`, `original_name`, `mime_type`,
`storage_path`, `sha256` (unique, which is the deduplication), `size_bytes`,
`page_count`, `quality`, `status` enum(quarantined, published, rejected),
`rejected_at`, `is_primary`, `download_count`, `uploaded_by`. The status says
which directory the file is in; `rejected_at` starts the clock the quarantine
sweep reads.

**`book_texts`** - `book_id`, `content` MEDIUMTEXT with a FULLTEXT index. Filled
by the upload pipeline from the extracted text.

**`categories`** - `parent_id`, `name`, `slug`, `path` (unique), `depth`,
`description`, `status` enum(active, pending, hidden), `sort_order`,
`book_count`, `proposed_by`. The self referencing foreign key cascades, so
deleting a node takes its subtree.

**`book_categories`** - (`book_id`, `category_id`).

**`tags`** - `name`, `slug` (unique), `status` enum(active, pending, rejected),
`usage_count`, `proposed_by`, `approved_by`.

**`tag_aliases`** - `alias` (unique), `tag_id`.

**`book_tags`** - (`book_id`, `tag_id`).

### The queue tables

**`moderation_requests`** - `subject_type` (a string, not an enum: the list grows
every milestone and an ALTER for what is really data is a poor trade),
`subject_id`, `submitter_id`, `assignee_id`, `status` enum(draft, pending,
under_review, changes_requested, approved, rejected, withdrawn), `title`,
`payload` JSON, `reason`, `claimed_until`, `decided_at`, `decided_by`.

**`moderation_events`** - `request_id`, `actor_id`, `from_status`, `to_status`,
`note`. Append only; this is the item's own history, shown to both sides.

**`moderation_comments`** - `request_id`, `author_id`, `body`. The conversation
between reviewer and submitter, so neither needs email to ask a question.

**`notifications`** - `user_id`, `type`, `title`, `body`, `url`, `read_at`.
Marked read when the page is opened.

**`book_requests`** - `title`, `author`, `isbn`, `note`, `language`,
`requester_id`, `status` enum(open, claimed, fulfilled, unavailable, duplicate,
rejected), `claimed_by`, `claimed_at`, `fulfilled_by_book_id`, `closed_by`,
`closed_at`, `close_reason`, `vote_count` (denormalised, recounted on every
vote). FULLTEXT over title, author and note.

**`book_request_votes`** - (`request_id`, `user_id`). The primary key is the
"one person, one vote" rule.

**`collections`** - `parent_id`, `root_id`, `owner_id`, `name`, `slug`, `path`
(unique), `depth`, `description`, `visibility` enum(private, unlisted, public),
`review_status` enum(none, pending, approved, rejected), `item_count`,
`follower_count`, `sort_order`, `forked_from_id`. The self referencing foreign
key cascades, so deleting a folder takes its subtree.

**`collection_items`** - (`collection_id`, `book_id`), `position`, `note`,
`added_by`.

**`collection_maintainers`** - (`collection_id`, `user_id`), `invited_by`. Held
against the root, not against each folder.

**`collection_followers`** - (`collection_id`, `user_id`), also against the root.

**`reading_progress`** - (`user_id`, `book_file_id`) unique, `position` (a
string: page number, CFI or percent depending on the format), `percent`,
`last_read_at`.

**`bookmarks`** - `user_id`, `book_file_id`, `position`, `label`, `note`.

**`reviews`** - (`book_id`, `user_id`) unique, `rating` 1 to 5, `body`, `status`
enum(visible, hidden, removed), `hidden_reason`, `helpful_count`. `books` gained
`rating_average` and `rating_count`.

**`review_votes`** - (`review_id`, `user_id`): one helpful vote per person.

**`reputation_events`** - `user_id`, `action`, `points` (negative for a revoke),
`subject_type`, `subject_id`. The pair makes an award idempotent.

**`badges`** - `key`, `name`, `description`, `action`, `threshold`. Seeded by its
migration; ten to begin with.

**`user_badges`** - (`user_id`, `badge_id`), `awarded_at`.

**`takedowns`** - `book_id`, `subject_url`, `claimant_name`, `claimant_email`,
`claimant_role`, `basis`, `status` enum(open, upheld, rejected), `outcome_note`,
`handled_by`, `handled_at`, `ip_hash`.

**`librarian_applications`** - `user_id`, `statement`, `status` enum(pending,
approved, rejected), `decided_by`, `decided_at`.

`users` also gained `strikes`, incremented by a copyright rejection.

## Migrations

Plain `.sql` files in `database/migrations`, named `NNNN_description.sql` and
applied in filename order. Each has an `@up` section and an `@down` section:

```sql
-- @up
CREATE TABLE `settings` (...);

-- @down
DROP TABLE IF EXISTS `settings`;
```

`Migrator` records what it applied in a `migrations` table with a batch number;
`migrate:rollback` undoes the most recent batch. A migration with no `@down`
throws on rollback rather than leaving the schema half undone, and CI rolls the
whole batch back and re-applies it on every run, so a missing `@down` fails the
build.

Statements are split on a semicolon at the end of a line. A statement containing
a semicolon inside a string literal has to be written on one line.

## Mail

`Core\Mailer` sends plain text and has three drivers, chosen by `MAIL_DRIVER`:

| Driver | Behaviour |
|---|---|
| `log` (default) | appends the message to `storage/logs/mail-YYYY-MM-DD.log` |
| `mail` | PHP's built-in `mail()` |
| `smtp` | PHPMailer over SMTP, using the other `MAIL_*` variables |

The default is `log` so a fresh install, CI and the test suite need no mail
server. It is also how the tests get at a confirmation or reset token: only the
hash is stored, so the link in the log file is the only copy
(`DatabaseTestCase::lastMailPath()`).

If `MAIL_DRIVER=smtp` and PHPMailer is missing (no `vendor/`), the mailer logs a
warning and falls back to the log driver rather than throwing.

Every message is plain text on purpose: each one is a link and a sentence, and
plain text cannot carry a tracking pixel or a broken template.

## File storage

Nothing under `storage/` is inside the webroot. `config/storage.php` defines:

| Path | Holds |
|---|---|
| `storage/library` | approved book files |
| `storage/quarantine` | uploads awaiting review, deleted 7 days after a rejection |
| `storage/covers` | cover images, rendered from page one of a PDF |
| `storage/cache` | rendered fragments and search artefacts |
| `storage/logs` | one JSON object per line, per day |
| `storage/backups` | database dumps written by the admin console |

Each directory keeps a `.gitignore` of `*` plus `!.gitignore`, so the directory
is tracked and the contents are not. `STORAGE_ROOT` relocates the whole tree.
How files get in and out is in [Uploads and moderation](#uploads-and-moderation);
`storage:init` creates the directories, `storage:verify` checks every stored file
is present and unchanged, and `quarantine:prune` deletes rejected uploads past
the grace window.

## Security model

- **Sessions**: cookie is `HttpOnly`, `SameSite=Lax`, `Secure` when
  `APP_FORCE_HTTPS=true`. Name and lifetime come from config.
  `Session::regenerate()` is called on sign in and on sign out, so a fixated id
  is worthless.
- **CSRF**: `VerifyCsrf` runs on every non-GET/HEAD/OPTIONS request in
  `routes/web.php` and rejects with 419. The token comes from `Csrf::field()` in
  a form, or the `X-CSRF-Token` header for fetch. Comparison is `hash_equals`.
- **CSP**: set by `SecurityHeaders` on every response. `script-src 'self'`, no
  `unsafe-inline`, `object-src 'none'`, `frame-ancestors 'none'`. Inline
  `<script>` will not run, and a feature test fails the build if a template adds
  one. `style-src` does allow `unsafe-inline`.
- **Other headers**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy` that
  denies geolocation, microphone and camera, and HSTS when HTTPS is forced.
- **Output escaping**: `$this->e()` in templates. The only unescaped output is
  `$this->slot()`, which holds HTML the view layer produced itself.
- **Errors**: `APP_DEBUG=false` in production. The debug 500 page prints the
  exception message, file and stack trace.

- **Authentication and authorisation**: see [Auth model](#auth-model) and
  [Permissions](#permissions). Sign-in attempts are throttled by account and by
  IP, and the public API and feeds are behind
  [rate limiting](#rate-limiting). Uploads are bounded by a per-account quota
  rather than by a request counter, because one large upload is the expensive
  case, not many small requests.
- **What a page may show**: a profile prints a name, username, role, bio and
  dates. Email addresses appear only on the owner's own settings page and in the
  admin user list.

## Console commands

```
php cli/console.php <command>
```

| Command | Does |
|---|---|
| `migrate` | apply pending migrations |
| `migrate:status` | list every migration and whether it is applied |
| `migrate:rollback` | roll back the most recent batch |
| `migrate:fresh` | drop every table and migrate again; refuses when `APP_ENV=production` |
| `db:create` | create the configured database if it does not exist |
| `db:seed` | add the 13 public domain books in `database/seeds/books.php`, skipping any already there |
| `catalogue:recount` | recalculate the category and tag counters |
| `quarantine:prune` | delete rejected uploads older than `storage.quarantine_days` |
| `storage:verify` | check every stored file exists and still hashes to its recorded SHA-256; exits 1 if not |
| `search:reindex` | re-read the text inside every published PDF and TXT and rebuild `book_texts` |
| `covers:generate [n]` | render page one of PDFs whose record has no cover; names the renderer it found |
| `key:generate` | write a new `APP_KEY` into `.env` |
| `user:promote <email> <role>` | set a role: member, librarian or admin. The way back in if you lock yourself out |
| `user:list` | the first 50 accounts with role, status and confirmation |
| `auth:prune` | delete expired tokens and login attempts older than a day |
| `route:list` | print the routing table from the router |
| `storage:init` | create the storage directories and report writability |
| `serve [host] [port]` | run the PHP development server on `public/` |
| `help` | the above |

## Environment variables

Read from `.env` by `App\Core\Env` (a 60-line parser, not phpdotenv, so the app
boots without `vendor/`). Values are never written to `$_ENV` or `putenv()`, so
they cannot leak into child processes. `.env` is gitignored; `.env.example` is the
template. Nothing here reaches the browser: there is no client bundle, and no
config value is printed into a page except `app.name`, `app.tagline` and
`app.version`.

| Variable | Default | Purpose |
|---|---|---|
| `APP_NAME` | Digital Library | shown in the header, the title and emails |
| `APP_KEY` | empty | keys the HMAC over IP addresses in `login_attempts` and `audit_logs`. Generate with `key:generate`. Changing it only resets those hashes |
| `APP_TAGLINE` | ... | meta description and the home page subtitle |
| `APP_ENV` | local | `production` blocks `migrate:fresh` |
| `APP_DEBUG` | true | shows exceptions on the error page. Set false in production |
| `APP_URL` | http://localhost:8000 | absolute URLs in emails and feeds |
| `APP_TIMEZONE` | Asia/Kolkata | display timezone; storage is always UTC |
| `APP_LOCALE` | en | the default language, and the `resources/lang` file to load |
| `APP_FORCE_HTTPS` | false | secure session cookie and HSTS |
| `SESSION_NAME` | dl_session | session cookie name |
| `SESSION_LIFETIME` | 7200 | session cookie lifetime in seconds |
| `DB_HOST` | 127.0.0.1 | |
| `DB_PORT` | 3306 | |
| `DB_DATABASE` | digital_library | |
| `DB_USERNAME` | root | |
| `DB_PASSWORD` | empty | |
| `STORAGE_ROOT` | `<repo>/storage` | where uploaded files live. Point it at another volume in production; the tests point it at a temporary directory |
| `MAX_UPLOAD_BYTES` | 209715200 | per-file cap, checked before anything is stored |
| `MAIL_DRIVER` | log | `log`, `mail` or `smtp`. See [Mail](#mail) |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_ENCRYPTION` | | used by the `smtp` driver |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | | envelope sender |

## Testing

```bash
composer test          # phpunit
composer lint          # phpcs, PSR-12
composer lint:fix      # phpcbf
composer stan          # phpstan level 6
composer check         # all three, the same set CI runs
```

`tests/Unit` covers the core in isolation: router matching and URL generation,
the middleware pipeline and its arguments, the validator, CSRF verification, view
rendering and escaping, config resolution, password hashing, the role enum, slug
generation, the fulltext query builder and migration parsing.

`tests/Feature` boots the real kernel through `bootstrap.php` and drives it with
`Request::create()`, asserting on the returned `Response` without a web server.
`$_SESSION` stands in for the cookie: it survives between the requests of one
test and is cleared between tests, so a test can sign in and then act as that
user. `TestCase::post()` adds a valid CSRF token, because every browser route
requires one.

### The database tests

Everything to do with accounts extends `Tests\DatabaseTestCase`, which needs
MySQL. It **skips itself unless `DB_DATABASE` contains "test"**, then migrates
once and truncates the account tables before each test. Truncating someone's
development library because they ran phpunit with the wrong `.env` is not a
mistake worth being possible.

To run them locally:

```bash
docker run --rm -d --name dl-test -e MYSQL_ROOT_PASSWORD=root \
    -e MYSQL_DATABASE=digital_library_test -p 3307:3306 mysql:8.4
# in .env: DB_PORT=3307, DB_DATABASE=digital_library_test, DB_USERNAME=root, DB_PASSWORD=root
composer test
```

Skipped tests are reported as skipped, not passed, so a run with no database
cannot look like a green one.

The database tests are integration tests: every request inside one boots a fresh
kernel with its own connection, and the tables are reset between tests. The whole
suite takes about three minutes on a laptop; it took nineteen before the reset
below was made incremental. That is
the price of testing the real thing rather than mocks, and it is why the unit
suite (`phpunit --testsuite Unit`, under two seconds) is worth running first
while you work.

The reset is incremental, because doing it the obvious way was costing more than
the tests: `DatabaseTestCase` asks in two queries which tables have anything in
them (an `EXISTS` each) and whether the seeded tables still match their seed (a
`CHECKSUM TABLE`), then truncates only those. A count would not do for the
seeded tables: a test that changes a setting's value leaves the same number of
rows behind. If any seeded table has moved, they all go back together, because
the seed inserts come from the migrations as a set. The first test of a run
clears everything unconditionally, since the database may hold anything when the
process starts, and the seed tables are also restored on the way *out* of a test:
the tests that need no database still read the settings table, so a test that
turned maintenance mode on would otherwise close the site for whatever ran
next.

Helpers do the setup: `makeUser()` writes an account straight to the table,
`makeBook()` creates a record with its authors, categories and tags through the
repositories, `makeRequest()` opens a book request with its requester's vote, and
`makeUpload()` returns a `$_FILES`-shaped array pointing at a
real temporary file so the pipeline runs exactly as it does for a browser upload
(`upload()` on the test case posts it). `STORAGE_ROOT` is pointed at
`sys_get_temp_dir()` and emptied around every test, so a test run never writes
into the developer's own storage tree. The starting categories and tags are seeded by their
migrations, so after the truncate `DatabaseTestCase` re-runs the INSERT
statements out of those same migration files rather than keeping a second copy of
the seed data.

Deliberately not covered: browser behaviour such as the theme toggle, and SMTP
delivery (the tests read the log driver's output instead).

Four tests are lints in disguise. `HomePageTest::testTheLayoutHasNoInlineScript`
fails if a template grows an inline `<script>`, because the CSP would silently
stop it from running in the browser. `RoutesTest` walks every registered route
and asserts the controller class, the method and the middleware all exist, and
that every named route can build its URL: `Foo::class` in a file missing its
`use Foo;` evaluates to the string `"Foo"` with no error, and without that test
the first sign of it is a 500 in production.
`LocaleTest::testEveryLocaleFileCarriesTheSameKeys` fails when a language file
gains or loses a key, so `en.php` stays the list of what is translatable.
`AccessibilityTest` walks the main pages and fails on an image with no `alt`, a
control with no label, a page with no `<h1>` or two, a rejected field that does
not point at its message, and a table without header cells.

## Continuous integration

`.github/workflows/ci.yml` runs on every push to `main` and every pull request,
with a MySQL 8.4 service container:

1. `composer install`
2. write a `.env` pointing at the service database, then `key:generate`
3. `php cli/console.php migrate`
4. `migrate:rollback` then `migrate` again, which fails the build if a migration
   has no working `@down`
5. `composer lint`, `composer stan`, `composer test`
6. start the dev server and assert `/health` reports `"ok": true`

Step 6 means a broken migration, a missing extension or an unwritable storage
directory fails CI, not just a failing assertion. Because the CI database is
called `digital_library_test`, the database tests run there even when a
contributor's local run skipped them.

**A PDF renderer is installed explicitly** (`poppler-utils`), before PHP is set
up. It is not a Composer dependency, so nothing else enforces it, and left
undeclared CI used whatever the runner image happened to ship. That is how five
cover tests failed on `main` while passing on every developer machine: the runner
has the Imagick extension but not Ghostscript, which Imagick needs to rasterise a
PDF. A step before the tests prints which renderers are present, so a failure of
that shape explains itself.

**Note the trigger.** The workflow runs on `main` and on pull requests only, so
work on a long-lived branch gets no CI until it is proposed for merge. That is
deliberate, since each run spins up a real MySQL, but it means a branch can
accumulate a lot of unverified commits. If you work on a branch for a while, open
the pull request early.

## Local development

Requirements: PHP 8.2+ with `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `gd` and
`openssl`, MySQL 8 or MariaDB 10.6+, and Composer for the dev tooling.

```bash
git clone <repo> && cd DigitalLibrary
composer install                    # dev tooling; the app runs without it
cp .env.example .env                # then edit DB_USERNAME and DB_PASSWORD
php cli/console.php key:generate
php cli/console.php db:create
php cli/console.php migrate
php cli/console.php storage:init
php cli/console.php serve           # http://127.0.0.1:8000
```

Register at `/register`: the first account becomes a confirmed admin. Later
accounts get a confirmation email, which with the default `MAIL_DRIVER=log` is
written to `storage/logs/mail-YYYY-MM-DD.log`.

Open the home page: the status panel says whether PHP, the database, the
migrations and the storage directories are all in order. `/health` returns the
same report as JSON, with 200 when everything is ready and 503 when it is not.

### With Docker

```bash
docker compose up --build                        # app on http://localhost:8000, MySQL on 3307
docker compose exec -u www-data app php cli/console.php migrate
docker compose exec -u www-data app php cli/console.php db:seed
docker compose --profile tools up adminer        # optional, on :8080
```

The checkout is bind-mounted so edits are live, but `storage/` is a named volume
so uploads survive a rebuild and Apache's `www-data` never writes into your
working copy.

**Run the console as `www-data`, not as root.** `docker compose exec` defaults to
root, and anything the console writes into `storage/` - a seeded cover, a
generated one, an uploaded file - is then owned by root with mode 0640. Apache
runs as `www-data` and cannot read it, so every cover 404s and the shelf looks
like a broken feature rather than a permissions mistake. If it has already
happened:

```bash
docker compose exec -u root app chown -R www-data:www-data storage
```

## Deployment

Point the document root at `public/`. Nothing else in the repo should be
reachable over HTTP.

Apache uses the shipped `public/.htaccess` and needs `mod_rewrite` and
`mod_headers`. The nginx equivalent:

```nginx
server {
    root /var/www/digital-library/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # PDF.js is an ES module and a module served as text/plain is refused.
    types { application/javascript mjs; }

    location ~ /\. { deny all; }
    client_max_body_size 256M;
}
```

Checklist for a real deployment: `APP_DEBUG=false`, `APP_ENV=production`,
`APP_FORCE_HTTPS=true`, a database user with no more than DML plus DDL on its own
schema, `storage/` writable by the web user and outside the webroot, and
`php cli/console.php migrate` as part of the release.

## Documentation

| File | For |
|---|---|
| `README.md` | Users. Dark mode gallery |
| `README-light.md` | The same page in light mode. **Generated** |
| `DEVDOC.md` | This file. Contributors |
| `PLAN.md` | The design and the road to it |
| `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` | How to take part |
| `not_for_you.md` | The author's working log. Not documentation |

`README-light.md` is generated by `scripts/build-light-readme.php`, which rewrites
the screenshot paths and flips the two toggle links. GitHub has no theme switch,
so the "toggle" is a pair of pages linking to each other. Edit `README.md`, run
the script, commit both:

```bash
php scripts/build-light-readme.php
```

It exits non-zero if a marker it needs is missing, so the light page cannot
silently drift into pointing at the dark screenshots. It is PHP rather than Node
so the repository keeps its promise of no toolchain beyond PHP and Composer.

Screenshots live in `docs/screenshots/{dark,light}` at 1440x900 and
`docs/screenshots/responsive/{dark,light}` at phone and tablet sizes, captured
against `db:seed` so anyone can reproduce them. **Seed after running the tests,
not before:** `DatabaseTestCase` truncates whatever a test dirtied, so a test run
empties the catalogue.

## Known constraints and gotchas

- **`preg_quote` eats route placeholders.** `Route::pattern()` quotes the literal
  parts one at a time instead of quoting the whole URI, because quoting first
  escapes the `{` and `}` and every parameterised route silently 404s. If you
  change the placeholder syntax, change `Route::PLACEHOLDER`; `Router::url()`
  shares the same constant.
- **`bootstrap.php` is re-run per test.** `Tests\TestCase::kernel()` `require`s it
  (not `require_once`) to get a clean application each time. That is why the
  autoloader is included with `require_once` and why `Autoloader::register()`
  guards `spl_autoload_register`. Remove either guard and PHPUnit dies with
  "Cannot declare class App\Core\Autoloader".
- **Migration splitting is naive.** Statements are split on `;` at end of line. A
  trigger body or a string containing a semicolon and a newline will be cut in
  half. Keep such a statement on one line.
- **The CSP silently breaks inline script.** Nothing fails server side; the code
  just never runs in the browser. Put JavaScript in `public/assets/js` and there
  is a test that enforces it.
- **Sessions do nothing on the CLI.** `Session::start()` returns early outside a
  web SAPI and backs everything with a plain `$_SESSION` array, so flash messages
  do not survive between console runs and tests start with an empty session.
- **The dev server needs workers for the reader.** PHP's built-in server handles
  one request at a time, and the reader asks for a module, a worker and the file
  at once. `console.php serve` sets `PHP_CLI_SERVER_WORKERS=4` for that reason;
  starting `php -S` by hand without it will look like the reader hanging.
- **`.mjs` has to be served as JavaScript.** Apache and nginx do not know the
  extension by default, and a module served as `text/plain` is refused by the
  browser with a message that does not mention MIME types. The shipped
  `.htaccess` and the nginx snippet above both set it.
- **Database timestamps are UTC; the application is not.** Never
  `strtotime($row['created_at'])`. Use `App\Support\Timestamp` in PHP and
  `$this->date()` in a template. The symptom is subtle: dates are a few hours
  out, and anything comparing a stored moment with `time()` (a claim, an
  expiry) is silently wrong.
- **`.banner` must stay a block.** It carries inline markup, links, `<code>`
  and sometimes a form; a flex container lays each of those out as its own
  narrow column. It looked like a rendering bug and it was a CSS one.
- **Two components may not share a class.** `.review` was both the review page
  layout and one review in a list, so every review in the book page rendered as
  a two column grid. The queue page now uses the shared `.page-body` shell.
- **The rate limiter fails open, on purpose.** A `storage/cache` that is not
  writable does not produce an error; it produces no limiting at all. If the
  limits look like they are not working, check the directory's permissions
  before reading the middleware.
- **`t()` is not automatic.** A string is English until somebody wraps it in
  `t()` and adds a line to `resources/lang/hi.php`. Switching to Hindi on a page
  whose strings were never wrapped shows English, which is correct behaviour and
  not a bug in the translator.
- **Search inside books only sees what was extracted.** A scanned PDF with no
  text layer contributes nothing to `book_texts`, so it is findable by title and
  author and by nothing else. `search:reindex` cannot fix that; only OCR could,
  and there is none.
- **JSON settings keep their quotes.** `settings.value` is a JSON column, so the
  string setting `site.name` is stored as `"Digital Library"` including the
  quotes, and reading it means `json_decode`. Forgetting that gets you a site
  name with quotes around it.
- **The status panel swallows database errors on purpose.** `SystemStatus`
  catches everything so the page can explain what is broken. Nothing else in the
  app should catch a connection failure.
- **A template's page data can be shadowed by the renderer's own locals.**
  `View::capture()` extracts with `EXTR_SKIP`, so a page variable whose name
  collides with a local in that method is silently dropped and the template
  renders with the wrong value. The locals are named `$__path` and `$__data` for
  that reason; do not pass page data under those names. This was a real bug: a
  page variable called `$file` picked up the template's own path and the review
  screen 500'd with "call to a member function on string".
- **`Foo::class` does not need `Foo` to exist.** In a file without the matching
  `use`, it evaluates to the short string `"Foo"`, the container cannot resolve
  it, and the route 500s at request time rather than at boot. `RoutesTest` is
  what catches it now; it caught exactly this in `routes/web.php` during M3.
- **`Request::create()` takes files as its fifth argument.** Setting the `$_FILES`
  global in a test does nothing, because a hand-built request never reads the
  globals. Use `TestCase::upload()`.
- **A streaming response fights `ob_get_clean()`.** The file controller calls
  `ob_flush()` as it goes, which empties a plain output buffer before the test
  can read it. `TestCase::bodyOf()` uses an output *handler* instead, which sees
  every chunk.
- **PHP's built-in server 404s a URI with a dot in it.** It treats
  `/admin/audit.csv` as a request for a missing static file. `console.php serve`
  passes `cli/dev-router.php` for that reason; Apache and nginx are fine because
  both fall through to the front controller when the file does not exist.
- **A filter dropdown contains every value it can filter by.** An audit test
  asserting the filtered page "does not contain user.status_changed" failed
  because the dropdown lists it. Match the row (`<code>action</code>`), not the
  page. Same family as the placeholder trap below.
- **`share()` is silent when it does not happen.** A service missing from the
  list in bootstrap.php still resolves, it is just rebuilt every time, throwing
  away whatever it cached. Several milestones' worth of additions had quietly
  failed to apply before `ContainerTest` was written to assert the list.
- **HTML wraps, so string assertions on a sentence break.** A template that
  writes "from 3 reviews" across three indented lines does not contain the string
  "from 3 reviews". `TestCase::flatten()` collapses the whitespace; use it rather
  than asserting on a fragment that happens to fit on one line today.
- **A `use` that silently does not exist.** `Foo::class` in a file without the
  import is the string `"Foo"`, and the container's error only appears at
  runtime. `RoutesTest` covers the route files; it happened again in
  `DatabaseTestCase` during M6, where a helper resolved `Tests\UserRepository`.
  If a container cannot resolve something obvious, check the imports first.
- **`Env::set()` outlives `Env::load()`, and has to.** Every kernel boot re-reads
  `.env`, so a value set in code would be undone on the next request unless it is
  held as an override. This was a real bug: the test suite pointed STORAGE_ROOT
  at a temporary directory, the next boot read the empty value out of `.env`, and
  every test upload landed in the developer's own `storage/` tree instead.
- **Covers depend on a tool that may not be there.** `CoverGenerator::available()`
  is false on a host without Imagick, pdftoppm or Ghostscript, and the tests that
  cover it skip themselves rather than failing. If covers stop appearing after a
  deploy, run `covers:generate` and read the first line: it names the renderer,
  or tells you there is none.
- **A placeholder is not a listing.** A test asserting a page "does not contain
  UPSC Preparation" passed for the wrong reason once the create form gained
  `placeholder="UPSC Preparation"`. Assert on the link (`/collections/the-slug`)
  rather than on a name that also appears in the furniture.
- **A subquery in the SELECT list binds before the WHERE clause.**
  `BookRequestRepository` adds a "has the viewer voted" column, so the viewer id
  is the *first* binding in those queries even though it reads last. Add a
  column like that and every binding after it shifts.
- **MySQL will not read the table an UPDATE is writing.** Error 1093. The
  subtree recount in `BookRepository::refreshCounters()` has to join a derived
  table instead of correlating a subquery back to `categories`; the tag recount
  next to it does not, because it only reads `book_tags` and `books`.
- **FULLTEXT ignores short words.** Anything under
  `innodb_ft_min_token_size` (three characters) is invisible to
  `MATCH ... AGAINST`, which is why the search ORs a `LIKE` and an author
  `EXISTS` alongside it. Dropping either half silently breaks two-letter
  searches or author searches.
- **Route order decides `/books/new`.** The router takes the first route that
  matches, so a literal path has to be declared before the `{slug}` route that
  would otherwise swallow it. Same for `/categories` and `/categories/{path...}`.
- **A published book's slug is frozen.** Renaming only regenerates the address
  while the record is not public; see [The catalogue](#the-catalogue).
- **`.gitignore` rules are matched at every level.** A bare `logs` line ignores
  `storage/logs/` and the `.gitignore` inside it, so a fresh clone comes out with
  no log directory and the app cannot write. The root rule is `/logs`; the
  contents of each storage directory are ignored by that directory's own
  `.gitignore`, which is what keeps the directory itself in the repo.
- **`BEFORE` and `AFTER` are reserved words in MySQL.** The audit columns are
  `before_state` and `after_state` for that reason; a column called `before`
  needs backticks in every query that touches it, and the day someone forgets is
  a syntax error at runtime.
- **`Request::create()` parses a query string.** `create('GET', '/admin/users?role=admin')`
  splits the path and fills the query bag, which is what makes filter tests work.
  It follows `fromGlobals()`; if you change one, change both.
- **Signing in over an existing session silently does nothing.**
  `RedirectIfAuthenticated` bounces a signed-in visitor away from `/login`, so a
  test that signs in as a second user without signing out keeps the first user's
  session and quietly asserts the wrong thing. `DatabaseTestCase::signIn()` signs
  out first for exactly this reason.
- **Bindings are typed, and MySQL cares.** `Db::run()` binds an int as
  `PARAM_INT` rather than passing everything to `execute()` as a string, because
  `LIMIT '25'` is a syntax error. Pass a real int for `LIMIT` and `OFFSET`.
- **A permission key is a string in three places**: the migration that seeds it,
  the route that names it in `Authorize:key`, and any `$gate->allows()` call. A
  typo in the middle one fails open only in the sense that the route throws 403
  for everyone, so it is loud rather than dangerous, but there is no compiler
  checking it.
- **Composer is optional at runtime, not for development.** The app boots with no
  `vendor/`, but `composer check` is what CI runs, so install it before opening a
  pull request.
