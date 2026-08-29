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
use App\Core\RateLimiter;
use App\Core\Router;
use App\Core\Session;
use App\Core\Storage;
use App\Core\Translator;
use App\Core\View;
use App\Middleware\MaintenanceMode;
use App\Middleware\SecurityHeaders;
use App\Middleware\SetLocale;
use App\Middleware\StartSession;
use App\Middleware\TrackLastSeen;
use App\Repositories\ApplicationRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\AuthorRepository;
use App\Repositories\BadgeRepository;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\BookRequestRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PublisherRepository;
use App\Repositories\ReadingRepository;
use App\Repositories\ReputationRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StatisticsRepository;
use App\Repositories\TagRepository;
use App\Repositories\TakedownRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\Auth;
use App\Services\BookRequestService;
use App\Services\BookService;
use App\Services\CollectionService;
use App\Services\CoverGenerator;
use App\Services\Gate;
use App\Services\ModerationService;
use App\Services\NotificationService;
use App\Services\ReputationService;
use App\Services\ReviewService;
use App\Services\TaxonomyService;
use App\Services\TextExtractor;
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
$container->singleton(Translator::class, static fn (): Translator => new Translator(
    BASE_PATH . '/resources/lang',
    (string) $config->get('app.locale', 'en')
));
$container->singleton(RateLimiter::class, static fn (): RateLimiter => new RateLimiter(
    (string) $config->get('storage.cache')
));
$container->singleton(Storage::class, static fn (): Storage => new Storage((array) $config->get('storage')));
$container->singleton(Mailer::class, static fn (Container $c): Mailer => new Mailer(
    (array) $config->get('mail'),
    $c->get(Logger::class),
    (string) $config->get('storage.logs')
));

// Resolved by autowiring, but only once per request: each of these either
// caches something (the signed-in user, the permission list, the settings
// table) or is asked for several times while handling one request.
//
// Anything missing from this list still works; it is just rebuilt on every
// resolution, which throws away its cache. ContainerTest keeps the list honest.
foreach (
    [
    UserRepository::class,
    PermissionRepository::class,
    AuthTokenRepository::class,
    LoginAttemptRepository::class,
    AuditLogRepository::class,
    SettingsRepository::class,
    BookRepository::class,
    BookFileRepository::class,
    AuthorRepository::class,
    PublisherRepository::class,
    CategoryRepository::class,
    TagRepository::class,
    ModerationRepository::class,
    NotificationRepository::class,
    BookRequestRepository::class,
    CollectionRepository::class,
    ReadingRepository::class,
    ReputationRepository::class,
    ReviewRepository::class,
    BadgeRepository::class,
    TakedownRepository::class,
    ApplicationRepository::class,
    StatisticsRepository::class,
    Auth::class,
    Gate::class,
    AccountService::class,
    BookService::class,
    TaxonomyService::class,
    NotificationService::class,
    CoverGenerator::class,
    TextExtractor::class,
    UploadPipeline::class,
    BookRequestService::class,
    CollectionService::class,
    ReputationService::class,
    ReviewService::class,
    ModerationService::class,
    ] as $service
) {
    $container->share($service);
}

$router = new Router();
(require BASE_PATH . '/routes/web.php')($router);
(require BASE_PATH . '/routes/api.php')($router);

$container->instance(Router::class, $router);

$container->singleton(View::class, static function (Container $c) use ($config, $router): View {
    $view = new View(
        BASE_PATH . '/app/Views',
        $router,
        $config,
        $c->get(Session::class),
        $c->get(Translator::class)
    );
    $view->share('csrf', $c->get(Csrf::class));
    $view->share('session', $c->get(Session::class));
    $view->share('auth', $c->get(Auth::class));
    $view->share('gate', $c->get(Gate::class));
    $view->share('notifications', $c->get(NotificationService::class));
    $view->share('settings', $c->get(SettingsRepository::class));

    // The switcher in the footer. English names, because someone looking for
    // their own language should not have to read another one to find it.
    $names = ['en' => 'English', 'hi' => 'हिन्दी'];
    $view->share('localeOptions', array_intersect_key($names, array_flip($c->get(Translator::class)->locales())));
    // The configured name is the fallback; the setting is what an admin edits.
    $view->share('appName', $c->get(SettingsRepository::class)->string(
        'site.name',
        (string) $config->get('app.name')
    ));

    return $view;
});

return new Kernel($container, $router, $config, [
    SecurityHeaders::class,
    StartSession::class,
    SetLocale::class,
    TrackLastSeen::class,
    MaintenanceMode::class,
]);
