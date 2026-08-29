<?php

/**
 * Command line entry point: php cli/console.php <command> [arguments]
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Router;
use App\Core\Storage;
use App\Repositories\AuditLogRepository;
use App\Repositories\AuthorRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\LoginAttemptRepository;
use App\Services\CoverGenerator;
use App\Services\TextExtractor;
use App\Repositories\TagRepository;
use App\Repositories\UserRepository;
use App\Support\Role;
use App\Support\Slug;

if (PHP_SAPI !== 'cli') {
    exit("The console can only be run from the command line.\n");
}

/** @var App\Core\Kernel $kernel */
$kernel = require dirname(__DIR__) . '/bootstrap.php';

$container = $kernel->container();
$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?? 'help';

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function fail(string $line): never
{
    fwrite(STDERR, $line . PHP_EOL);

    exit(1);
}

try {
    switch ($command) {
        case 'migrate':
            $migrator = $container->get(Migrator::class);
            $applied = $migrator->migrate(static fn (string $name) => out('  applied  ' . $name));
            out($applied === [] ? 'Nothing to migrate.' : count($applied) . ' migration(s) applied.');

            break;

        case 'migrate:status':
            $migrator = $container->get(Migrator::class);
            $applied = $migrator->applied();

            foreach ($migrator->files() as $name) {
                out(sprintf('  %-9s %s', in_array($name, $applied, true) ? 'applied' : 'pending', $name));
            }

            break;

        case 'migrate:rollback':
            $migrator = $container->get(Migrator::class);
            $rolled = $migrator->rollback(static fn (string $name) => out('  rolled back  ' . $name));
            out($rolled === [] ? 'Nothing to roll back.' : count($rolled) . ' migration(s) rolled back.');

            break;

        case 'migrate:fresh':
            $config = $container->get(Config::class);

            if ($config->get('app.env') === 'production') {
                fail('Refusing to run migrate:fresh in production.');
            }

            $db = $container->get(Db::class);
            $database = (string) $config->get('database.database');
            $db->execute('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($db->select('SHOW TABLES') as $row) {
                $table = (string) array_values($row)[0];
                $db->execute('DROP TABLE IF EXISTS `' . $table . '`');
                out('  dropped  ' . $table);
            }

            $db->execute('SET FOREIGN_KEY_CHECKS = 1');
            out('Dropped every table in ' . $database . '.');

            $container->get(Migrator::class)->migrate(static fn (string $name) => out('  applied  ' . $name));

            break;

        case 'db:seed':
            $books = $container->get(BookRepository::class);
            $authors = $container->get(AuthorRepository::class);
            $categories = $container->get(CategoryRepository::class);
            $tags = $container->get(TagRepository::class);
            $added = 0;
            $skipped = 0;

            foreach (require BASE_PATH . '/database/seeds/books.php' as $seed) {
                $slug = Slug::make((string) $seed['title'], 260);

                if ($books->slugTaken($slug)) {
                    $skipped++;

                    continue;
                }

                $id = $books->create([
                    'title'          => $seed['title'],
                    'subtitle'       => $seed['subtitle'] ?? null,
                    'slug'           => $slug,
                    'published_year' => $seed['year'] ?? null,
                    'language'       => $seed['language'] ?? 'en',
                    'description'    => $seed['description'] ?? null,
                    'content_type'   => $seed['content_type'] ?? 'book',
                    'licence'        => $seed['licence'] ?? 'public_domain',
                    'source_url'     => $seed['source_url'] ?? null,
                    'page_count'     => $seed['pages'] ?? null,
                    'status'         => 'published',
                    'published_at'   => gmdate('Y-m-d H:i:s'),
                ]);

                $books->syncAuthors($id, $authors->resolveMany(
                    array_map('trim', explode(',', (string) ($seed['authors'] ?? '')))
                ));

                $categoryIds = [];

                foreach ((array) ($seed['categories'] ?? []) as $path) {
                    $category = $categories->findByPath((string) $path);

                    if ($category !== null) {
                        $categoryIds[] = $category->id;
                    }
                }

                $books->syncCategories($id, $categoryIds);
                $books->syncTags($id, $tags->resolveMany((array) ($seed['tags'] ?? []), null, true));

                out('  added  ' . $seed['title']);
                $added++;
            }

            $books->refreshCounters();
            out(sprintf('%d book(s) added, %d already there.', $added, $skipped));

            break;

        case 'quarantine:prune':
            $config = $container->get(Config::class);
            $storage = $container->get(Storage::class);
            $files = $container->get(BookFileRepository::class);
            $days = (int) $config->get('storage.quarantine_days', 7);
            $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
            $removed = 0;

            foreach ($files->rejectedBefore($cutoff) as $file) {
                $storage->delete($file['storage_path']);
                $files->delete($file['id']);
                $removed++;
                out('  deleted  ' . $file['storage_path']);
            }

            out(sprintf('%d rejected file(s) older than %d days removed.', $removed, $days));

            break;

        case 'covers:generate':
            $covers = $container->get(CoverGenerator::class);
            $storage = $container->get(Storage::class);
            $files = $container->get(BookFileRepository::class);
            $books = $container->get(BookRepository::class);

            if (!$covers->available()) {
                fail(
                    'No PDF renderer on this host. Install poppler-utils (pdftoppm), '
                    . 'Ghostscript, or the Imagick extension.'
                );
            }

            out('Renderer: ' . (string) $covers->renderer());
            $made = 0;
            $failed = 0;

            foreach ($files->pdfsWithoutCover((int) ($arguments[0] ?? 200)) as $file) {
                $path = $covers->fromPdf($storage->absolute($file['storage_path']), $file['sha256']);

                if ($path === null) {
                    $failed++;
                    out('  could not render  book ' . $file['book_id']);

                    continue;
                }

                $books->update($file['book_id'], ['cover_path' => $path]);
                $made++;
                out('  cover  book ' . $file['book_id'] . '  ' . $path);
            }

            out(sprintf('%d cover(s) made, %d could not be rendered.', $made, $failed));

            break;

        case 'storage:verify':
            $storage = $container->get(Storage::class);
            $files = $container->get(BookFileRepository::class);
            $checked = 0;
            $missing = 0;
            $corrupt = 0;

            foreach ($files->all() as $file) {
                $checked++;

                if (!$storage->exists($file['storage_path'])) {
                    $missing++;
                    out('  MISSING   ' . $file['storage_path']);

                    continue;
                }

                if ($storage->hash($storage->absolute($file['storage_path'])) !== $file['sha256']) {
                    $corrupt++;
                    out('  CHANGED   ' . $file['storage_path']);
                }
            }

            out(sprintf(
                '%d file(s) checked, %d missing, %d with a different hash. Library uses %s.',
                $checked,
                $missing,
                $corrupt,
                number_format($storage->usage('library') / 1048576, 1) . ' MB'
            ));

            if ($missing > 0 || $corrupt > 0) {
                exit(1);
            }

            break;

        case 'search:reindex':
            $storage = $container->get(Storage::class);
            $files = $container->get(BookFileRepository::class);
            $extractor = $container->get(TextExtractor::class);
            $indexed = 0;
            $skipped = 0;

            foreach ($files->all() as $file) {
                $format = pathinfo($file['storage_path'], PATHINFO_EXTENSION);

                if (!in_array($format, ['pdf', 'txt'], true) || $file['status'] !== 'published') {
                    $skipped++;

                    continue;
                }

                $extracted = $extractor->fromFile($storage->absolute($file['storage_path']), $format);

                if ($extracted['text'] === null) {
                    $skipped++;

                    continue;
                }

                $files->storeTextForFile($file['id'], $extracted['text']);
                $indexed++;
            }

            out(sprintf('%d file(s) indexed, %d skipped (no text, or not published).', $indexed, $skipped));

            break;

        case 'catalogue:recount':
            $container->get(BookRepository::class)->refreshCounters();
            out('Category and tag counters recalculated.');

            break;

        case 'db:create':
            $config = $container->get(Config::class);
            $database = (string) $config->get('database.database');
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                (string) $config->get('database.host'),
                (string) $config->get('database.port')
            );

            $pdo = new PDO(
                $dsn,
                (string) $config->get('database.username'),
                (string) $config->get('database.password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database
            ));
            out('Database ' . $database . ' is ready.');

            break;

        case 'key:generate':
            $key = base64_encode(random_bytes(32));
            $envPath = BASE_PATH . '/.env';

            if (!is_file($envPath)) {
                out('No .env file yet. Copy .env.example first, then run this again.');
                out('APP_KEY=' . $key);

                break;
            }

            $contents = (string) file_get_contents($envPath);
            $replaced = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents, 1, $count);

            if ($count === 0) {
                $replaced = rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
            }

            file_put_contents($envPath, (string) $replaced);
            out('APP_KEY written to .env.');

            break;

        case 'user:promote':
            $email = $arguments[0] ?? '';
            $role = Role::tryFrom(strtolower($arguments[1] ?? ''));

            if ($email === '' || $role === null) {
                fail('Usage: user:promote <email> <member|librarian|admin>');
            }

            $users = $container->get(UserRepository::class);
            $user = $users->findByEmail($email);

            if ($user === null) {
                fail('No account with the email ' . $email . '.');
            }

            $users->setRole($user->id, $role);
            $container->get(AuditLogRepository::class)->record(
                null,
                'user.role_changed',
                'user',
                $user->id,
                ['role' => $user->role->value],
                ['role' => $role->value, 'via' => 'console']
            );
            out($user->username . ' is now a ' . $role->label() . '.');

            break;

        case 'user:list':
            $results = $container->get(UserRepository::class)->paginate([], 1, 50);

            foreach ($results['rows'] as $user) {
                out(sprintf(
                    '  %-24s %-28s %-10s %-8s %s',
                    $user->username,
                    $user->email,
                    $user->role->value,
                    $user->status->value,
                    $user->isVerified() ? 'confirmed' : 'unconfirmed'
                ));
            }

            out(sprintf('%d of %d account(s).', count($results['rows']), $results['total']));

            break;

        case 'auth:prune':
            $tokens = $container->get(AuthTokenRepository::class)->pruneExpired();
            $attempts = $container->get(LoginAttemptRepository::class)->prune(86400);
            out(sprintf('%d expired token(s) and %d old login attempt(s) removed.', $tokens, $attempts));

            break;

        case 'route:list':
            foreach ($container->get(Router::class)->routes() as $route) {
                out(sprintf(
                    '  %-7s %-28s %-46s %s',
                    implode('|', array_diff($route->methods(), ['HEAD'])),
                    $route->uri(),
                    $route->handlerName(),
                    $route->getName() ?? ''
                ));
            }

            break;

        case 'storage:init':
            $config = $container->get(Config::class);

            foreach (['library', 'quarantine', 'covers', 'cache', 'logs', 'backups'] as $name) {
                $path = (string) $config->get('storage.' . $name);

                if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                    fail('Could not create ' . $path);
                }

                out(sprintf('  %-11s %s', $name, is_writable($path) ? 'writable' : 'NOT WRITABLE'));
            }

            break;

        case 'serve':
            $config = $container->get(Config::class);
            $host = $arguments[0] ?? '127.0.0.1';
            $port = $arguments[1] ?? '8000';
            // The reader loads a module, a worker and the file at once; a
            // single process server would serialise them into a stall.
            putenv('PHP_CLI_SERVER_WORKERS=4');
            out('Serving ' . $config->get('app.name') . ' on http://' . $host . ':' . $port);
            // The router script is what makes a URI with a dot in it, such as
            // /admin/audit.csv, reach the application instead of 404ing as a
            // missing static file.
            passthru(sprintf(
                '%s -S %s:%s -t %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg(BASE_PATH . '/public'),
                escapeshellarg(BASE_PATH . '/cli/dev-router.php')
            ));

            break;

        case 'help':
        default:
            out('Digital Library console');
            out();
            out('  migrate            apply pending migrations');
            out('  migrate:status     list migrations and whether they are applied');
            out('  migrate:rollback   roll back the most recent batch');
            out('  migrate:fresh      drop every table and migrate again (never in production)');
            out('  db:create          create the configured database if it does not exist');
            out('  db:seed            add the public domain seed books, skipping any already there');
            out('  catalogue:recount  recalculate the category and tag book counters');
            out('  search:reindex     re-read the text inside every published PDF and TXT');
            out('  key:generate       write a new APP_KEY into .env');
            out('  user:promote <email> <role>  set a role: member, librarian or admin');
            out('  user:list          list the first 50 accounts');
            out('  auth:prune         delete expired tokens and old login attempts');
            out('  quarantine:prune   delete rejected uploads past the grace window');
            out('  storage:verify     check every stored file is present and unchanged');
            out('  covers:generate [n]  make covers from page one for PDFs that have none');
            out('  route:list         list registered routes');
            out('  storage:init       create the storage directories and check they are writable');
            out('  serve [host] [port]  run the PHP development server');

            break;
    }
} catch (Throwable $e) {
    fail('Error: ' . $e->getMessage());
}
