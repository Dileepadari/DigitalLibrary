<?php
/**
 * The reviews on a book page: the summary, the form, and the list.
 *
 * @var App\Core\View $this
 * @var App\Models\Book $book
 * @var list<array<string, mixed>> $reviews
 * @var array<string, mixed>|null $mine
 * @var array<int, int> $distribution
 */
$me = $this->auth->user();
$stars = static fn (int $rating): string => str_repeat('&#9733;', $rating) . str_repeat('&#9734;', 5 - $rating);
?>
<?php if (!$this->settings->bool('features.reviews', true)) : ?>
    <?php return; ?>
<?php endif ?>

<section class="panel" id="reviews">
    <h2>Reviews</h2>

    <div class="rating-summary">
        <div class="rating-summary__score">
            <strong><?= $book->ratingCount > 0 ? number_format($book->ratingAverage, 1) : '&ndash;' ?></strong>
            <span class="rating-stars" aria-hidden="true"><?= $stars((int) round($book->ratingAverage)) ?></span>
            <span class="status-item__detail">
                <?= (int) $book->ratingCount ?> review<?= $book->ratingCount === 1 ? '' : 's' ?>
            </span>
        </div>

        <?php if ($book->ratingCount > 0) : ?>
            <ul class="rating-bars">
                <?php foreach ([5, 4, 3, 2, 1] as $star) : ?>
                    <li>
                        <span class="rating-bars__label"><?= $star ?></span>
                        <span class="rating-bars__track">
                            <span class="rating-bars__fill"
                                  style="width: <?= $book->ratingCount === 0
                                      ? 0
                                      : (int) round(($distribution[$star] / $book->ratingCount) * 100) ?>%"></span>
                        </span>
                        <span class="status-item__detail"><?= (int) $distribution[$star] ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>

    <?php if ($me !== null && $this->gate->allows('review.write')) : ?>
        <form method="post" action="<?= $this->url('reviews.store', ['slug' => $book->slug]) ?>" class="stack">
            <?= $this->csrf->field() ?>

            <div class="field">
                <label for="rating"><?= $mine === null ? 'Your rating' : 'Change your rating' ?></label>
                <select id="rating" name="rating" required>
                    <?php foreach ([5, 4, 3, 2, 1] as $star) : ?>
                        <option value="<?= $star ?>"
                            <?= $mine !== null && (int) $mine['rating'] === $star ? 'selected' : '' ?>>
                            <?= $star ?> star<?= $star === 1 ? '' : 's' ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <?php $this->include('partials/field-errors', ['field' => 'rating']) ?>
            </div>

            <div class="field">
                <label for="body">What did you make of it</label>
                <textarea id="body" name="body" rows="3" maxlength="4000"><?=
                    $this->e((string) ($mine['body'] ?? ''))
                ?></textarea>
                <p class="field__hint">Optional. A rating on its own is a perfectly good review.</p>
            </div>

            <div class="book__actions">
                <button type="submit" class="button button--small">
                    <?= $mine === null ? 'Post the review' : 'Update it' ?>
                </button>

                <?php if ($mine !== null) : ?>
                    <button type="submit" form="delete-review" class="link-button">Take it back</button>
                <?php endif ?>
            </div>
        </form>

        <?php if ($mine !== null) : ?>
            <form method="post" id="delete-review"
                  action="<?= $this->url('reviews.delete', ['id' => (int) $mine['id']]) ?>">
                <?= $this->csrf->field() ?>
            </form>
        <?php endif ?>
    <?php elseif ($me === null) : ?>
        <p class="muted"><a href="<?= $this->url('login') ?>">Sign in</a> to review this book.</p>
    <?php endif ?>

    <?php if ($reviews === []) : ?>
        <p class="muted">Nobody has said anything yet.</p>
    <?php endif ?>

    <?php foreach ($reviews as $review) : ?>
        <article class="review <?= $review['status'] !== 'visible' ? 'review--hidden' : '' ?>">
            <p class="review__head">
                <span class="rating-stars" aria-label="<?= (int) $review['rating'] ?> out of 5">
                    <?= $stars((int) $review['rating']) ?>
                </span>
                <a href="<?= $this->url('profile', ['username' => (string) $review['username']]) ?>">
                    <?= $this->e((string) $review['username']) ?>
                </a>
                <span class="status-item__detail">
                    <?= $this->e(date('j M Y', strtotime((string) $review['created_at']))) ?>
                    <?php if ($review['status'] !== 'visible') : ?>
                        &middot; hidden: <?= $this->e((string) $review['hidden_reason']) ?>
                    <?php endif ?>
                </span>
            </p>

            <?php if (($review['body'] ?? null) !== null) : ?>
                <p><?= nl2br($this->e((string) $review['body'])) ?></p>
            <?php endif ?>

            <p class="review__foot">
                <?php if ($me !== null && (int) $review['user_id'] !== $me->id
                    && $this->gate->allows('review.write')) : ?>
                    <form method="post"
                          action="<?= $this->url('reviews.helpful', ['id' => (int) $review['id']]) ?>"
                          class="inline-form">
                        <?= $this->csrf->field() ?>
                        <button type="submit" class="link-button">
                            <?= ((int) ($review['viewer_voted'] ?? 0)) > 0 ? 'Helpful, yes' : 'Helpful?' ?>
                        </button>
                    </form>
                <?php endif ?>

                <span class="status-item__detail">
                    <?= (int) $review['helpful_count'] ?>
                    <?= (int) $review['helpful_count'] === 1 ? 'person found' : 'people found' ?> this helpful
                </span>

                <?php if ($this->gate->allows('review.moderate')) : ?>
                    <form method="post"
                          action="<?= $this->url('reviews.moderate', ['id' => (int) $review['id']]) ?>"
                          class="inline-form">
                        <?= $this->csrf->field() ?>
                        <select name="status" aria-label="Review status">
                            <option value="visible" <?= $review['status'] === 'visible' ? 'selected' : '' ?>>
                                Visible
                            </option>
                            <option value="hidden" <?= $review['status'] === 'hidden' ? 'selected' : '' ?>>
                                Hidden
                            </option>
                        </select>
                        <input type="text" name="reason" placeholder="Reason" size="12" aria-label="Reason">
                        <button type="submit" class="button button--small button--quiet">Set</button>
                    </form>
                <?php endif ?>
            </p>
        </article>
    <?php endforeach ?>
</section>
