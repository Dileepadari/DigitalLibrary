<?php

/**
 * Boots the application and returns a Kernel. Both public/index.php and the
 * test suite go through here, so they cannot drift apart.
 */

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Env;
use App\Core\Kernel;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Migrator;
use App\Core\Router;
use App\Core\Session;
use App\Core\Storage;
use App\Core\View;
use App\Middleware\SecurityHeaders;
use App\Middleware\StartSession;
use App\Middleware\TrackLastSeen;
use App\Repositories\AuditLogRepository;
use App\Repositories\AuthorRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\BookRequestRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PublisherRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TagRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\Auth;
use App\Services\BookRequestService;
use App\Services\BookService;
use App\Services\CollectionService;
use App\Services\Gate;
use App\Services\ModerationService;
use App\Services\NotificationService;
use App\Services\TaxonomyService;
use App\Services\UploadPipeline;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/app/Core/Autoloader.php';

Autoloader::register(['App\\' => BASE_PATH . '/app/']);

// Composer is optional: it carries the dev tooling and, from M3, the PDF
// parser. Nothing in app/Core depends on it.
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}

Env::load(BASE_PATH . '/.env');

$config = new Config(BASE_PATH . '/config');

date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

$container = new Container();
$container->instance(Container::class, $container);
$container->instance(Config::class, $config);

$container->singleton(Db::class, static fn (): Db => new Db((array) $config->get('database')));
$container->singleton(Logger::class, static fn (): Logger => new Logger((string) $config->get('storage.logs')));
$container->singleton(Session::class, static fn (): Session => new Session($config));
$container->singleton(Csrf::class, static fn (Container $c): Csrf => new Csrf($c->get(Session::class)));
$container->singleton(Migrator::class, static fn (Container $c): Migrator => new Migrator(
    $c->get(Db::class),
    BASE_PATH . '/database/migrations'
));
$container->singleton(Storage::class, static fn (): Storage => new Storage((array) $config->get('storage')));
$container->singleton(Mailer::class, static fn (Container $c): Mailer => new Mailer(
    (array) $config->get('mail'),
    $c->get(Logger::class),
    (string) $config->get('storage.logs')
));

// Resolved by autowiring, but only once per request: each of these caches
// something (the signed-in user, the permission list, the settings table).
foreach (
    [
    UserRepository::class,
    PermissionRepository::class,
    AuthTokenRepository::class,
    LoginAttemptRepository::class,
    AuditLogRepository::class,
    SettingsRepository::class,
    Auth::class,
    Gate::class,
    AccountService::class,
    ] as $service
) {
    $container->share($service);
}

$router = new Router();
(require BASE_PATH . '/routes/web.php')($router);
(require BASE_PATH . '/routes/api.php')($router);

$container->instance(Router::class, $router);

$container->singleton(View::class, static function (Container $c) use ($config, $router): View {
    $view = new View(BASE_PATH . '/app/Views', $router, $config, $c->get(Session::class));
    $view->share('csrf', $c->get(Csrf::class));
    $view->share('session', $c->get(Session::class));
    $view->share('auth', $c->get(Auth::class));
    $view->share('gate', $c->get(Gate::class));
    $view->share('notifications', $c->get(NotificationService::class));
    $view->share('appName', $config->get('app.name'));

    return $view;
});

return new Kernel($container, $router, $config, [
    SecurityHeaders::class,
    StartSession::class,
    TrackLastSeen::class,
]);
