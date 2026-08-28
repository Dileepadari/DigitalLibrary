<?php

/**
 * Browser routes. Everything in this file goes through CSRF verification, so a
 * form that posts without the hidden field from Csrf::field() gets a 419
 * rather than silently working.
 */

declare(strict_types=1);

use App\Controllers\Admin\UserController;
use App\Controllers\Auth\EmailVerificationController;
use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\Auth\RegisterController;
use App\Controllers\Librarian\CatalogueController;
use App\Controllers\Librarian\QueueController;
use App\Controllers\Librarian\TaxonomyController;
use App\Controllers\Web\BookController;
use App\Controllers\Web\BookFormController;
use App\Controllers\Web\CategoryController;
use App\Controllers\Web\CollectionController;
use App\Controllers\Web\FileController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\RequestController;
use App\Controllers\Web\SettingsController;
use App\Controllers\Web\SubmissionController;
use App\Controllers\Web\TagController;
use App\Controllers\Web\UploadController;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\VerifyCsrf;

return static function (Router $router): void {
    $router->group(['middleware' => [VerifyCsrf::class]], static function (Router $router): void {
        $router->get('/', [HomeController::class, 'index'])->name('home');
        $router->get('/u/{username}', [ProfileController::class, 'show'])->name('profile');

        // The catalogue. /books/new is declared before /books/{slug} because the
        // router takes the first route that matches, and {slug} would eat it.
        $router->get('/books', [BookController::class, 'index'])->name('books');
        $router->get('/search', [BookController::class, 'index'])->name('search');

        $router->group([
            'middleware' => [Authenticate::class, Authorize::class . ':book.upload'],
        ], static function (Router $router): void {
            $router->get('/books/new', [BookFormController::class, 'create'])->name('books.new');
            $router->post('/books', [BookFormController::class, 'store'])->name('books.store');
            $router->get('/books/{slug}/edit', [BookFormController::class, 'edit'])->name('books.edit');
            $router->post('/books/{slug}/edit', [BookFormController::class, 'update'])->name('books.update');
            $router->post('/books/{slug}/files', [UploadController::class, 'store'])->name('books.files');
        });

        // The only way bytes leave storage/.
        $router->get('/files/{id:[0-9]+}', [FileController::class, 'show'])
            ->middleware(Authenticate::class, Authorize::class . ':book.download')
            ->name('file');

        $router->post('/books/{slug}/status', [BookFormController::class, 'status'])
            ->middleware(Authenticate::class, Authorize::class . ':book.publish')
            ->name('books.status');

        $router->get('/books/{slug}', [BookController::class, 'show'])->name('book');

        $router->get('/categories', [CategoryController::class, 'index'])->name('categories');
        $router->post('/categories/propose', [TaxonomyController::class, 'storeCategory'])
            ->middleware(Authenticate::class, Authorize::class . ':taxonomy.propose')
            ->name('categories.propose');
        $router->get('/categories/{path...}', [CategoryController::class, 'show'])->name('category');

        // Collections. The list and any public node are readable by anyone; the
        // rest needs an account, and the service decides whose collection it is.
        $router->get('/collections', [CollectionController::class, 'index'])->name('collections');
        $router->post('/collections', [CollectionController::class, 'store'])
            ->middleware(Authenticate::class, Authorize::class . ':collection.create.private');
        $router->post('/collections/add', [CollectionController::class, 'addFromBook'])
            ->middleware(Authenticate::class, Authorize::class . ':collection.create.private')
            ->name('collections.add');
        $router->post('/collections/{id:[0-9]+}', [CollectionController::class, 'act'])
            ->middleware(Authenticate::class)
            ->name('collection.act');
        $router->get('/collections/{path...}', [CollectionController::class, 'show'])->name('collection');

        $router->get('/tags', [TagController::class, 'index'])->name('tags');
        $router->get('/tags/{slug}', [TagController::class, 'show'])->name('tag');

        // Book requests. Reading them is public; everything else needs an account
        // and the matching permission.
        $router->get('/requests', [RequestController::class, 'index'])->name('requests');
        $router->get('/requests/{id:[0-9]+}', [RequestController::class, 'show'])->name('request');

        $router->post('/requests', [RequestController::class, 'store'])
            ->middleware(Authenticate::class, Authorize::class . ':request.create');
        $router->post('/requests/{id:[0-9]+}/vote', [RequestController::class, 'vote'])
            ->middleware(Authenticate::class, Authorize::class . ':request.vote')
            ->name('request.vote');
        $router->post('/requests/{id:[0-9]+}', [RequestController::class, 'act'])
            ->middleware(Authenticate::class)
            ->name('request.act');

        // Signed out only.
        $router->group(['middleware' => [RedirectIfAuthenticated::class]], static function (Router $router): void {
            $router->get('/register', [RegisterController::class, 'create'])->name('register');
            $router->post('/register', [RegisterController::class, 'store']);

            $router->get('/login', [LoginController::class, 'create'])->name('login');
            $router->post('/login', [LoginController::class, 'store']);

            $router->get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
            $router->post('/forgot-password', [PasswordResetController::class, 'send']);

            $router->get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
            $router->post('/reset-password/{token}', [PasswordResetController::class, 'update']);
        });

        // The link in the email has to work whether or not they are signed in.
        $router->get('/verify-email/{token}', [EmailVerificationController::class, 'consume'])
            ->name('verify.consume');

        // Signed in only.
        $router->group(['middleware' => [Authenticate::class]], static function (Router $router): void {
            $router->post('/logout', [LoginController::class, 'destroy'])->name('logout');

            $router->get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verify.notice');
            $router->post('/verify-email/resend', [EmailVerificationController::class, 'resend'])
                ->name('verify.resend');

            $router->get('/me/submissions', [SubmissionController::class, 'index'])->name('submissions');
            $router->get('/me/submissions/{id:[0-9]+}', [SubmissionController::class, 'show'])->name('submission');
            $router->post('/me/submissions/{id:[0-9]+}', [SubmissionController::class, 'act'])
                ->name('submission.act');
            $router->get('/me/notifications', [SubmissionController::class, 'notifications'])
                ->name('notifications');

            $router->get('/me/settings', [SettingsController::class, 'edit'])->name('settings');
            $router->post('/me/settings', [SettingsController::class, 'update']);
            $router->post('/me/password', [SettingsController::class, 'password'])->name('settings.password');
        });

        // The moderation queue. `moderation.queue` is what a librarian is for.
        $router->group([
            'prefix'     => '/librarian',
            'middleware' => [Authenticate::class, Authorize::class . ':moderation.queue'],
        ], static function (Router $router): void {
            $router->get('/queue', [QueueController::class, 'index'])->name('queue');
            $router->get('/queue/{id:[0-9]+}', [QueueController::class, 'show'])->name('queue.show');
            $router->post('/queue/{id:[0-9]+}/claim', [QueueController::class, 'claim'])->name('queue.claim');
            $router->post('/queue/{id:[0-9]+}/release', [QueueController::class, 'release'])->name('queue.release');
            $router->post('/queue/{id:[0-9]+}/decide', [QueueController::class, 'decide'])->name('queue.decide');
            $router->post('/queue/{id:[0-9]+}/comment', [QueueController::class, 'comment'])->name('queue.comment');
        });

        // Librarians and above: the catalogue and the taxonomy.
        $router->group([
            'prefix'     => '/librarian',
            'middleware' => [Authenticate::class, Authorize::class . ':taxonomy.manage'],
        ], static function (Router $router): void {
            $router->get('/books', [CatalogueController::class, 'index'])->name('librarian.books');
            $router->get('/taxonomy', [TaxonomyController::class, 'index'])->name('librarian.taxonomy');
            $router->post('/categories', [TaxonomyController::class, 'storeCategory'])
                ->name('librarian.categories.store');
            $router->post('/categories/{id:[0-9]+}/decide', [TaxonomyController::class, 'decideCategory'])
                ->name('librarian.categories.decide');
            $router->post('/tags/{id:[0-9]+}/decide', [TaxonomyController::class, 'decideTag'])
                ->name('librarian.tags.decide');
            $router->post('/tags/{id:[0-9]+}/alias', [TaxonomyController::class, 'storeAlias'])
                ->name('librarian.tags.alias');
        });

        // Admin only. `user.manage` is carried by the admin role alone.
        $router->group([
            'prefix'     => '/admin',
            'middleware' => [Authenticate::class, Authorize::class . ':user.manage'],
        ], static function (Router $router): void {
            $router->get('/users', [UserController::class, 'index'])->name('admin.users');
            $router->post('/users/{id:[0-9]+}/role', [UserController::class, 'updateRole'])->name('admin.users.role');
            $router->post('/users/{id:[0-9]+}/status', [UserController::class, 'updateStatus'])
                ->name('admin.users.status');
        });
    });
};
