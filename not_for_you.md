# not_for_you.md

A personal working log. Not documentation, and nothing here is needed to use or contribute to this project. Everything a newcomer actually needs is in [README.md](./README.md) and [DEVDOC.md](./DEVDOC.md).

---

## CI was red on `main`, and had been since the branch was merged

The first thing worth saying: **the default branch was failing and nothing said so.** The workflow only triggers on `main` and on pull requests, so the nine commits of real work on `build/digital-library` never ran it. PR #1 merged them, CI ran for the first time, five tests failed, and there it sat.

Five failures, all in `CoverGenerationTest`, all the same shape: the cover path came back empty.

### The cause was not what it looked like

`CoverGenerator` rasterises page one of a PDF using whatever the host has, preferring Imagick, then `pdftoppm`, then Ghostscript. It documents that a host with none of them simply gets no covers, and `CoverGenerationTest::setUp` skips itself when `available()` is false. So on a renderer-less host the tests should have *skipped*, not failed.

They failed because the GitHub runner **has the Imagick extension but not Ghostscript**. Imagick delegates PDF rasterising to Ghostscript, so it is present and cannot do the one thing it was picked for. `renderer()` reported `imagick`, `available()` said yes, `withImagick()` produced nothing, and `fromPdf` gave up.

That is a real bug, not a CI quirk: **`renderer()` reported capability by presence rather than ability, and `fromPdf` tried exactly one renderer.** Any host with a broken or policy-restricted Imagick, which on Debian and Ubuntu is the common case because `policy.xml` disables the PDF coder by default, silently made no covers while a working `pdftoppm` sat unused beside it.

Fixed in three places:

- `renderer()` became `renderers()`, returning every option best-first. `renderer()` remains as the head of that list, for the CLI's "which one will you use" line.
- `fromPdf` loops, and moves to the next renderer when one produces nothing, truncating the temporary file in between so a failed attempt cannot be mistaken for output.
- CI and the `Dockerfile` both install `poppler-utils` explicitly. The image shipping without any renderer was its own quiet bug: it ran fine and never made a cover.

CI added a step that prints which renderers are present, so the next failure of this shape explains itself instead of looking like a logic error. It is what turned the diagnosis from guessing into reading (`imagick: yes / pdftoppm: /usr/bin/pdftoppm / gs: none`).

## `covers:generate` was broken on any stock MySQL 8

Found by running it rather than reading it:

```
SQLSTATE[42000]: ... Expression #2 of SELECT list is not in GROUP BY clause and
contains nonaggregated column 'f.storage_path' ... incompatible with
sql_mode=only_full_group_by
```

`pdfsWithoutCover()` did `GROUP BY f.book_id` while selecting `f.storage_path` and `f.sha256`. That has been rejected by MySQL's **default** sql_mode since 5.7, so the command could never have worked on a default install. It also left which of a book's files you got undefined, even on a server lenient enough to run it.

Rewritten as a correlated `f.id = (SELECT MIN(f2.id) ...)`, which is valid everywhere and deterministically picks the earliest PDF.

Nothing caught it because the query is reachable only from the CLI and had no test at all. `tests/Feature/CoverBackfillQueryTest.php` now covers it: that it runs, that a book with two PDFs is offered once and specifically the earlier one, and that a book with a cover is skipped. Verified the test actually catches the bug by putting the old query back: three errors, then three passes with the fix restored.

## Things worth knowing for next time

- **The tests truncate the database.** `DatabaseTestCase` empties whatever a test dirtied, so running `composer test` mid-session wipes the seed and any account you registered. Seed after testing, not before, or you will screenshot an empty catalogue and wonder why. It cost me one round of captures.
- The session expires and every subsequent capture silently becomes the sign-in page. Assert on page content, not on the navigation succeeding.
- `Page.captureScreenshot` froze twice for thirty seconds on a page that was perfectly ordinary (243 elements, 2537px tall). Checked before blaming the app; it was the browser. Reloading the tab cleared it.
- The app sends `frame-ancestors 'none'` and `X-Frame-Options: DENY`, correctly, so the capture harness went behind a throwaway proxy that strips exactly those two headers. It lived outside the repository and is gone.
- Theme is `data-theme` on `<html>` plus localStorage, so forcing one for a capture is one `setAttribute`.
- The light-README builder refused to run at first because the marker sentence had been line-wrapped. That is the guard doing its job: the alternative is a light page that silently keeps pointing at the dark screenshots.

## Open threads

- **The workflow still only triggers on `main` and pull requests.** That is what let nine commits of work land without CI. Adding `branches: ['**']` or at least the `build/*` pattern would catch it earlier, but it also multiplies runs against a real MySQL service, so it is a deliberate hold rather than an oversight.
- No test exercises `withImagick` against a broken Imagick, because there is no clean way to install one. The fallthrough is verified by CI's environment happening to be exactly that case, which is lucky rather than designed.
- `covers:generate` is the only CLI command with a test. The rest (`storage:verify`, `search:reindex`, `auth:prune`, `quarantine:prune`) are equally unexercised and equally capable of carrying a query that no server will accept.
- 440 tests take about four and a half minutes locally, which is close to the point where people stop running them before pushing.
