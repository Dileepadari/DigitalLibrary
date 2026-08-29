<p align="center">
  <img src="./public/assets/img/logo-mark.png" width="96" alt="ADK DEV">
</p>

# Digital Library

An open source digital library that a community fills in together. Members find
books, read them in the browser, ask for the ones that are missing and upload the
ones they have. Librarians review everything before it goes public. Admins run the
place.

It is built to be self-hosted: PHP and MySQL, no build step, no cloud account.
For architecture, data model and setup, see **[DEVDOC.md](./DEVDOC.md)**.
For the full design and the road to it, see **[PLAN.md](./PLAN.md)**.

## Where the project is

Milestones 0 to 8 are complete: the application core, accounts with the three
roles and a real permission system, the catalogue, uploads with the review queue
behind them, book requests, collections, reading in the browser, the community
layer of reviews, reputation and badges, and the admin surface that runs it. A member can ask for a book, upvote what someone
else asked for, add a book and attach a file; a librarian reviews it; approving
it publishes the file and tells everyone who wanted it. Files can be read in the
browser or downloaded.

| Milestone | What it adds | State |
|---|---|---|
| M0 Foundation | MVC core, routing, views, theme, config, migrations, Docker, CI | done |
| M1 Identity | registration, email confirmation, sign in, password reset, roles, permissions, profiles, admin user list | done |
| M2 Catalogue | books, authors, categories, tags, browse, faceted search, seed data | done |
| M3 Uploads | upload pipeline, quarantine, the moderation queue, review screen, notifications | done |
| M4 Requests | book requests, votes, claiming, fulfilment linked to uploads | done |
| M5 Collections | the deep folder tree, sharing, forking and following | done |
| M6 Reading | PDF and EPUB readers, reading progress, bookmarks | done |
| M7 Community | reviews, ratings, reputation, badges, the contributor board | done |
| M8 Admin | settings, audit log viewer, storage dashboard, analytics, takedowns, librarian applications | done |
| M9 Polish | full text search, public API, OPDS and RSS, Hindi, accessibility | next |

What works today:

- Register, confirm your email, sign in, sign out, reset a forgotten password
- A public profile at `/u/username`, and your own settings at `/me/settings`
- Roles and 30 permissions, checked on every route that needs one
- Browse and search the catalogue by title, author, description, category, tag,
  kind and language, with counts beside every facet
- A book page with its authors, shelves, tags, licence basis and related reading
- Add a book: a librarian's goes straight in, a member's waits for review
- Categories to any depth, tags with approval and aliases, and a librarian screen
  to decide on both
- Upload a file: validated, hashed, deduplicated and held in quarantine until a
  librarian approves it, then hard linked into the library
- A cover made from the first page of an uploaded PDF, when the record has none
- A moderation queue with claims, canned reasons, a comment thread and a full
  event log, plus your own view of what you submitted and what came back
- Downloads with range support, counted, and served only to people allowed them
- Read PDFs, EPUBs and text in the browser, with the page you were on remembered
  per person and bookmarks you can name
- Rate and review a book, mark someone else's review helpful, and see the rating
  on every listing. Librarians can hide a review, with a reason the author sees
- Reputation for work the library keeps, badges for doing it repeatedly, and a
  contributor board at `/contributors`
- An admin surface: site settings and feature flags that take effect at once, a
  filterable audit log with a CSV export, a storage dashboard, thirty days of
  numbers, and maintenance mode
- A public takedown form anyone can use without an account, and an admin console
  that hides a book the moment a notice is upheld
- Members can apply to become librarians; only an admin decides
- Book requests: ask for what is missing, upvote what others asked for, claim one
  to work on, and answer one by adding the book. Everyone who voted is told when
  it arrives
- Collections: folders to any depth, private while you build them, published as a
  whole through the queue. Follow one to hear when a book is added, fork a public
  one into your own, invite someone to co-maintain it
- 13 public domain seed books, so a fresh install is not an empty shelf
- An admin user list with search and filters, role changes, bans and timed mutes
- An audit log recording registrations, sign-ins, role and status changes, and
  every catalogue and taxonomy decision
- The install status panel on the home page, `/health`, and the console commands
  in [DEVDOC.md](./DEVDOC.md#console-commands)

## Roles

Three levels of use, plus guests. A role is a bundle of permission keys stored in
the database, so an admin can also hand one extra permission to one person
without promoting them.

| Role | Can do |
|---|---|
| **Guest** | browse and search public metadata |
| **Member** | read and download, request books, upload books, propose categories and tags, build private shelves, propose public collections |
| **Librarian** | everything a member can, plus review the queue: approve or reject uploads, edits, taxonomy proposals and collections, publish directly, moderate reviews |
| **Admin** | everything a librarian can, plus approve librarian applications, manage users and roles, site settings, takedowns, the audit log and storage |

A member becomes a librarian by applying; only an admin can approve the
application. Applications themselves arrive with the moderation queue in M3;
until then an admin promotes people directly from the user list.

**The first account registered on a fresh install becomes the admin**, already
confirmed. An install with no admin could never promote anyone, so someone has to
be able to open the door. After that, `php cli/console.php user:promote <email>
admin` is the way in if you lock yourself out.

## How a contribution becomes a book

Everything a member submits moves through one state machine, whether it is a
file, a new category or a request to publish a collection:

```
draft -> pending -> under_review -> approved
                 |              |-> rejected
                 |              |-> changes_requested -> pending
                 |-> withdrawn
```

A reviewer claims an item before working on it, which locks it for half an hour
so two librarians do not review the same thing; the lock expires so an abandoned
review does not block the queue. Rejection needs a reason, and the canned ones
keep decisions consistent. Nobody decides on their own submission unless they are
an admin, and then the audit entry says so. Approving an upload moves the file
out of quarantine into the library, notifies the uploader and credits them.
Every transition is written to both the item's own history and the audit log.

Book uploads, book records with no file yet, category proposals and collection
publications share this queue. Librarian applications join it as they are built.

## Asking for a book

If the library does not have something, ask for it at `/requests`. Anyone can
upvote a request, and the list is worked in order of demand rather than order of
arrival. Someone who is going to find the book can claim it so two people do not
scan the same thing. When a book that answers a request is added, approving it
fulfils the request and notifies everyone who voted, in one step: nobody has to
remember to go back and close it.

## Organising books

Three separate layers, on purpose:

- **Categories** are the fixed shelf structure: Stories, Kids, Movies, Academics,
  Comics. Hierarchical to any depth, curated by librarians. Browsing one shows
  everything below it too, and the count beside it says how much that is.
  Anyone may propose a category; a librarian decides.
- **Tags** are the descriptive layer: romance, science fiction, biography, ncert.
  Anyone can add one to a book straight away, but a tag nobody has approved
  stays out of the tag list and the suggestions until a librarian activates it,
  which is what keeps sci-fi, scifi and science fiction from becoming three
  different things. Aliases fold the synonyms that arrive anyway.
- **Collections** are the folders anyone can build, to any depth:

```
UPSC Preparation
├── Prelims
│   ├── History
│   │   ├── Ancient India
│   │   └── Modern India
│   └── Polity
└── Previous Year Papers
```

A collection is private while you build it, and goes to the review queue when you
want it public: the whole tree is reviewed at once, because that is what people
will see. Others can follow it and hear when a book is added, or fork it into
their own collections and take it their own way. The owner can invite anyone to
help maintain it.

## Tech stack

PHP 8.3 with a small hand-written MVC core, MySQL 8 over PDO, plain PHP
templates, and vanilla JavaScript. No framework, no build step, and the only
Composer packages are the three dev tools. Files are stored outside the webroot and
streamed by a controller that checks permissions first.

## Getting started

```bash
composer install
cp .env.example .env          # then set DB_USERNAME and DB_PASSWORD
php cli/console.php key:generate
php cli/console.php db:create
php cli/console.php migrate
php cli/console.php db:seed          # optional: 13 public domain books
php cli/console.php serve
```

Open http://127.0.0.1:8000 and register: the first account is the admin. Mail is
written to `storage/logs/mail-YYYY-MM-DD.log` until you configure SMTP, so the
confirmation link is there rather than in an inbox.

Docker, requirements and everything else are in [DEVDOC.md](./DEVDOC.md#local-development).

## Contributing

Read [CONTRIBUTING.md](./CONTRIBUTING.md). Issues labelled `good first issue` are
scoped to a single file. `composer check` runs everything CI runs.

## Licence

[AGPL-3.0-or-later](./LICENSE). If you run a modified copy as a public service,
your changes have to be published too.

If you host an instance, you are responsible for what is on it. The upload flow
records a licence basis for every file and the admin console has a takedown
process, but neither of those makes the operator's obligations go away.
