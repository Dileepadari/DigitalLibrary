<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Db;
use App\Core\Env;
use App\Core\Migrator;
use App\Repositories\AuthorRepository;
use App\Repositories\BookRepository;
use App\Repositories\BookRequestRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\TagRepository;
use App\Repositories\UserRepository;
use App\Services\ModerationService;
use App\Services\UploadPipeline;
use App\Support\Slug;

/**
 * Base class for the tests that need MySQL.
 *
 * These skip themselves unless DB_DATABASE names a database with "test" in it.
 * They truncate tables between tests, and truncating someone's development
 * library because they ran phpunit with the wrong .env is not a mistake worth
 * being possible.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;

    /** @var list<string> emptied before each test, children first */
    private const TABLES = [
        'bookmarks',
        'reading_progress',
        'collection_followers',
        'collection_maintainers',
        'collection_items',
        'collections',
        'book_request_votes',
        'book_requests',
        'notifications',
        'moderation_comments',
        'moderation_events',
        'moderation_requests',
        'book_texts',
        'book_files',
        'book_tags',
        'book_categories',
        'book_authors',
        'books',
        'authors',
        'publishers',
        'tag_aliases',
        'tags',
        'categories',
        'audit_logs',
        'login_attempts',
        'auth_tokens',
        'user_permissions',
        'users',
    ];

    /** Migrations whose seed rows the tests rely on and the truncate removes. */
    private const SEEDED = ['0007_create_categories_table', '0008_create_tags_table'];

    protected Db $db;

    /** Where uploaded files go during a test run. */
    private string $storageRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Uploads must not land in the developer's own storage tree.
        $this->storageRoot = sys_get_temp_dir() . '/digital-library-tests';
        Env::set('STORAGE_ROOT', $this->storageRoot);

        foreach (['library', 'quarantine', 'covers', 'cache', 'logs', 'backups'] as $directory) {
            $path = $this->storageRoot . '/' . $directory;

            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        $this->clearStorage();

        $container = $this->kernel()->container();
        $config = $container->get(Config::class);
        $database = (string) $config->get('database.database');

        if (!str_contains($database, 'test')) {
            $this->markTestSkipped(
                "Refusing to touch the database [{$database}]. Point DB_DATABASE at a "
                . 'database with "test" in the name to run the database tests.'
            );
        }

        $this->db = $container->get(Db::class);

        if (!$this->db->isConnected()) {
            $this->markTestSkipped('No database connection: ' . (string) $this->db->connectionError());
        }

        if (!self::$migrated) {
            $container->get(Migrator::class)->migrate();
            self::$migrated = true;
        }

        $this->truncate();
        $this->reseedTaxonomy($container->get(Migrator::class));
    }

    protected function tearDown(): void
    {
        $this->clearStorage();

        parent::tearDown();
    }

    /** Empties the test storage tree without removing the directories. */
    private function clearStorage(): void
    {
        foreach (['library', 'quarantine', 'covers'] as $directory) {
            $path = $this->storageRoot . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo) {
                    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
                }
            }
        }
    }

    /**
     * A $_FILES-shaped array pointing at a real temporary file, so the pipeline
     * runs exactly as it does for a browser upload.
     *
     * @return array<string, mixed>
     */
    protected function makeUpload(string $name, string $contents, ?int $error = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dlupload');

        if ($path === false) {
            $this->fail('Could not create a temporary upload file.');
        }

        file_put_contents($path, $contents);

        return [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $path,
            'error'    => $error ?? UPLOAD_ERR_OK,
            'size'     => strlen($contents),
        ];
    }

    /** The smallest thing finfo will call a PDF. */
    protected function pdfBytes(string $marker = 'Hello'): string
    {
        return "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n"
            . "% {$marker}\n"
            . "trailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function truncate(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $this->db->execute('TRUNCATE TABLE `' . $table . '`');
        }

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * The starting categories and tags are seeded by their migrations, and the
     * truncate above takes them with it. Re-running the INSERT statements out of
     * the same files keeps one source of truth for what a fresh install has.
     */
    private function reseedTaxonomy(Migrator $migrator): void
    {
        foreach (self::SEEDED as $migration) {
            foreach ($migrator->statements($migration, 'up') as $sql) {
                if (stripos(ltrim($sql), 'INSERT') === 0) {
                    $this->db->execute($sql);
                }
            }
        }
    }

    /**
     * A published catalogue record, straight through the repositories.
     *
     * @param array{status?: string, authors?: list<string>, categories?: list<string>,
     *              tags?: list<string>, year?: int, language?: string, type?: string} $options
     */
    protected function makeBook(string $title, array $options = []): int
    {
        $container = $this->kernel()->container();
        $books = $container->get(BookRepository::class);

        $status = $options['status'] ?? 'published';

        $id = $books->create([
            'title'          => $title,
            'slug'           => Slug::unique($title, static fn (string $s): bool => $books->slugTaken($s), 260),
            'description'    => $options['description'] ?? null,
            'published_year' => $options['year'] ?? null,
            'language'       => $options['language'] ?? 'en',
            'content_type'   => $options['type'] ?? 'book',
            'licence'        => 'public_domain',
            'status'         => $status,
            'added_by'       => $options['added_by'] ?? null,
            'published_at'   => $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ]);

        if (($options['authors'] ?? []) !== []) {
            $books->syncAuthors($id, $container->get(AuthorRepository::class)->resolveMany($options['authors']));
        }

        $categoryIds = [];

        foreach ($options['categories'] ?? [] as $path) {
            $category = $container->get(CategoryRepository::class)->findByPath($path);

            if ($category !== null) {
                $categoryIds[] = $category->id;
            }
        }

        $books->syncCategories($id, $categoryIds);

        if (($options['tags'] ?? []) !== []) {
            $books->syncTags($id, $container->get(TagRepository::class)->resolveMany($options['tags'], null, true));
        }

        $books->refreshCounters();

        return $id;
    }

    /**
     * Registers an account straight through the service, bypassing the form.
     *
     * @return array{id: int, email: string, password: string}
     */
    protected function makeUser(
        string $username = 'reader',
        string $role = 'member',
        bool $verified = true,
        string $status = 'active',
    ): array {
        $email = $username . '@example.com';
        $password = 'correct-horse-battery';

        $id = $this->db->insert('users', [
            'name'              => ucfirst($username),
            'username'          => $username,
            'email'             => $email,
            'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
            'role'              => $role,
            'status'            => $status,
            'email_verified_at' => $verified ? gmdate('Y-m-d H:i:s') : null,
        ]);

        return ['id' => $id, 'email' => $email, 'password' => $password];
    }

    /**
     * The most recent link containing this fragment from the mail log.
     *
     * MAIL_DRIVER=log writes messages to storage/logs/mail-YYYY-MM-DD.log, which
     * is the only way to get at a token: only its hash is stored.
     */
    protected function lastMailLink(string $fragment): ?string
    {
        // The log driver writes under the configured storage root, which the
        // tests move to a temporary directory.
        $logs = (string) $this->kernel()->container()->get(Config::class)->get('storage.logs');
        $path = $logs . '/mail-' . date('Y-m-d') . '.log';

        if (!is_file($path)) {
            return null;
        }

        $pattern = '#\S*' . preg_quote($fragment, '#') . '[A-Za-z0-9]+#';

        if (
            preg_match_all($pattern, (string) file_get_contents($path), $matches) !== false
            && $matches[0] !== []
        ) {
            return (string) end($matches[0]);
        }

        return null;
    }

    /** The path part of the most recent link containing this fragment. */
    protected function lastMailPath(string $fragment): ?string
    {
        $link = $this->lastMailLink($fragment);

        if ($link === null) {
            return null;
        }

        $path = parse_url($link, PHP_URL_PATH);

        return is_string($path) ? $path : null;
    }

    /**
     * An open book request, straight through the repository.
     *
     * @param array{author?: string, status?: string, note?: string} $options
     */
    protected function makeRequest(string $title, ?int $requesterId = null, array $options = []): int
    {
        $repository = $this->kernel()->container()->get(BookRequestRepository::class);

        $id = $repository->create([
            'title'        => $title,
            'author'       => $options['author'] ?? null,
            'note'         => $options['note'] ?? null,
            'requester_id' => $requesterId,
            'status'       => $options['status'] ?? 'open',
        ]);

        if ($requesterId !== null) {
            $repository->addVote($id, $requesterId);
        }

        return $id;
    }

    /**
     * A published book with a published file on it, ready to be read.
     *
     * @return array{book_id: int, file_id: int, slug: string, uploader: int}
     */
    protected function makeReadableBook(string $title = 'A Readable Book', string $format = 'pdf'): array
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $container = $this->kernel()->container();

        $bookId = $this->makeBook($title, ['added_by' => $librarian['id']]);
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($librarian['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $upload = $format === 'pdf'
            ? $this->makeUpload('book.pdf', $this->pdfBytes($title))
            : $this->makeUpload('book.txt', 'The text of ' . $title . ".\n");

        $result = $container->get(UploadPipeline::class)->receive($upload, $book, $user);

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertNotNull($result->file);

        $container->get(ModerationService::class)->submitUpload($book, $result->file, $user, true);

        return [
            'book_id'  => $bookId,
            'file_id'  => $result->file->id,
            'slug'     => $book->slug,
            'uploader' => $librarian['id'],
        ];
    }

    /**
     * Signs in through the real form, so the session looks like a browser's.
     *
     * Signs out first when someone else is already in: the sign-in page
     * redirects a signed-in visitor away, which would otherwise leave the wrong
     * account in the session and make the test lie.
     */
    protected function signIn(string $email, string $password = 'correct-horse-battery'): void
    {
        if (isset($_SESSION['user_id'])) {
            $this->post('/logout');
        }

        $response = $this->post('/login', ['email' => $email, 'password' => $password]);

        $this->assertSame('/', $response->headers()['Location'] ?? null, 'Sign in did not succeed.');
    }
}
