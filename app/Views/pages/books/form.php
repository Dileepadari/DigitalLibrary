<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Book|null $book
 * @var list<App\Models\Category> $categories
 * @var App\Models\BookRequest|null $bookRequest
 */

use App\Support\ContentType;
use App\Support\Licence;

$this->layout('layouts/app');
$this->section('title');
echo $book === null ? 'Add a book' : 'Edit ' . $this->e($book->title);
$this->end();

$prefill = $book === null && $bookRequest !== null;
$value = function (string $field, ?string $current) : string {
    $old = $this->old($field);

    return $old !== '' ? $old : (string) $current;
};
$action = $book === null
    ? $this->url('books.store')
    : $this->url('books.update', ['slug' => $book->slug]);
$selectedCategories = $book === null
    ? []
    : array_map(static fn ($category): int => $category->id, $book->categories);
$tagValue = $book === null
    ? ''
    : implode(', ', array_map(static fn ($tag): string => $tag->name, $book->tags));
$authorValue = $book === null
    ? ''
    : implode(', ', array_map(static fn ($author): string => $author->name, $book->authors));

// Coming from a request, the title and author are already known.
if ($prefill) {
    $titleValue = $bookRequest->title;
    $authorValue = (string) $bookRequest->author;
}
?>
<section class="stack-wide">
    <h1><?= $book === null ? 'Add a book' : 'Edit this record' ?></h1>

    <?php if ($bookRequest !== null) : ?>
        <p class="banner banner--ok">
            Answering a request:
            <a href="<?= $this->url('request', ['id' => $bookRequest->id]) ?>">
                <?= $this->e($bookRequest->summary()) ?>
            </a>.
            <?= (int) $bookRequest->voteCount ?> <?= $bookRequest->voteCount === 1 ? 'person wants' : 'people want' ?>
            it, and they all hear when it arrives.
        </p>
    <?php endif ?>

    <?php if (!$this->gate->allows('book.publish')) : ?>
        <p class="banner banner--warn">
            A librarian reviews what you add before it appears in the catalogue.
        </p>
    <?php endif ?>

    <form method="post" action="<?= $action ?>" class="stack panel" enctype="multipart/form-data">
        <?= $this->csrf->field() ?>

        <?php if ($bookRequest !== null) : ?>
            <input type="hidden" name="request_id" value="<?= (int) $bookRequest->id ?>">
        <?php endif ?>

        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title"<?= $this->errorAttributes('title') ?> required maxlength="255"
                   value="<?= $this->e($value('title', $prefill ? $titleValue : $book?->title)) ?>">
            <?php $this->include('partials/field-errors', ['field' => 'title']) ?>
        </div>

        <div class="field">
            <label for="subtitle">Subtitle</label>
            <input type="text" id="subtitle" name="subtitle" maxlength="255"
                   value="<?= $this->e($value('subtitle', $book?->subtitle)) ?>">
        </div>

        <div class="field">
            <label for="authors">Authors</label>
            <input type="text" id="authors" name="authors"<?= $this->errorAttributes('authors') ?> maxlength="500"
                   value="<?= $this->e($value('authors', $authorValue)) ?>">
            <p class="field__hint">Comma separated. New names are created as you type them.</p>
            <?php $this->include('partials/field-errors', ['field' => 'authors']) ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="content_type">Kind</label>
                <select id="content_type" name="content_type">
                    <?php foreach (ContentType::all() as $type) : ?>
                        <option value="<?= $this->e($type->value) ?>"
                            <?= ($book?->contentType ?? ContentType::Book) === $type ? 'selected' : '' ?>>
                            <?= $this->e($type->label()) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="field">
                <label for="language">Language</label>
                <input type="text" id="language" name="language" maxlength="5" size="4"
                       value="<?= $this->e($value('language', $book?->language ?? 'en')) ?>">
                <p class="field__hint">Two letter code, such as en or hi.</p>
            </div>

            <div class="field">
                <label for="published_year">Year</label>
                <input type="number" id="published_year" name="published_year" min="1000" max="2100"
                       value="<?= $this->e($value('published_year', (string) $book?->publishedYear)) ?>">
            </div>

            <div class="field">
                <label for="page_count">Pages</label>
                <input type="number" id="page_count" name="page_count" min="1"
                       value="<?= $this->e($value('page_count', (string) $book?->pageCount)) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="publisher">Publisher</label>
                <input type="text" id="publisher" name="publisher" maxlength="160"
                       value="<?= $this->e($value('publisher', $book?->publisherName)) ?>">
            </div>

            <div class="field">
                <label for="edition">Edition</label>
                <input type="text" id="edition" name="edition" maxlength="60"
                       value="<?= $this->e($value('edition', $book?->edition)) ?>">
            </div>

            <div class="field">
                <label for="isbn13">ISBN-13</label>
                <input type="text" id="isbn13" name="isbn13" maxlength="17"
                       value="<?= $this->e($value('isbn13', $book?->isbn13)) ?>">
            </div>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="5"><?=
                $this->e($value('description', $book?->description))
            ?></textarea>
        </div>

        <div class="field">
            <label for="tags">Tags</label>
            <input type="text" id="tags" name="tags" maxlength="500"
                   value="<?= $this->e($value('tags', $tagValue)) ?>">
            <p class="field__hint">
                Comma separated. A tag nobody has used before waits for a librarian
                before it appears in the tag list.
            </p>
        </div>

        <fieldset class="field">
            <legend>Categories</legend>
            <div class="checkbox-grid">
                <?php foreach ($categories as $category) : ?>
                    <label class="checkbox">
                        <input type="checkbox" name="categories[]" value="<?= (int) $category->id ?>"
                            <?= in_array($category->id, $selectedCategories, true) ? 'checked' : '' ?>>
                        <?= $this->e(str_repeat('- ', $category->depth) . $category->name) ?>
                    </label>
                <?php endforeach ?>
            </div>
        </fieldset>

        <?php if ($book === null) : ?>
            <div class="field">
                <label for="book_file">File</label>
                <input type="file" id="book_file" name="book_file"
                       accept=".pdf,.epub,.mobi,.djvu,.cbz,.txt">
                <p class="field__hint">
                    Optional. PDF, EPUB, MOBI, DJVU, CBZ or TXT. A record with no file is
                    still a useful catalogue entry, and someone can attach one later.
                </p>
            </div>
        <?php endif ?>

        <div class="field">
            <label for="licence">Licence basis</label>
            <select id="licence" name="licence"<?= $this->errorAttributes('licence') ?> required>
                <?php foreach (Licence::all() as $licence) : ?>
                    <option value="<?= $this->e($licence->value) ?>"
                        <?= ($book?->licence ?? Licence::Unknown) === $licence ? 'selected' : '' ?>>
                        <?= $this->e($licence->label()) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <p class="field__hint">
                Why this may be here. Whoever runs this library has to be able to answer
                that for everything on it.
            </p>
            <?php $this->include('partials/field-errors', ['field' => 'licence']) ?>
        </div>

        <div class="field">
            <label for="licence_note">Licence note</label>
            <input type="text" id="licence_note" name="licence_note" maxlength="255"
                   value="<?= $this->e($value('licence_note', $book?->licenceNote)) ?>">
        </div>

        <div class="field">
            <label for="source_url">Source URL</label>
            <input type="url" id="source_url" name="source_url"<?= $this->errorAttributes('source_url') ?> maxlength="500"
                   value="<?= $this->e($value('source_url', $book?->sourceUrl)) ?>">
            <?php $this->include('partials/field-errors', ['field' => 'source_url']) ?>
        </div>

        <button type="submit" class="button"><?= $book === null ? 'Add to the library' : 'Save' ?></button>
    </form>
</section>
