<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Storage;
use App\Core\View;
use App\Repositories\BookFileRepository;
use App\Services\CoverGenerator;

/**
 * What is on disk, and whether it matches what the database thinks.
 */
final class StorageController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Storage $storage,
        private readonly BookFileRepository $files,
        private readonly CoverGenerator $covers,
        private readonly Config $config,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $missing = [];
        $counts = ['quarantined' => 0, 'published' => 0, 'rejected' => 0];

        foreach ($this->files->all() as $file) {
            $counts[$file['status']] = ($counts[$file['status']] ?? 0) + 1;

            if (!$this->storage->exists($file['storage_path'])) {
                $missing[] = $file;
            }
        }

        return $this->render('pages/admin/storage', [
            'usage' => [
                'library'    => $this->storage->usage('library'),
                'quarantine' => $this->storage->usage('quarantine'),
                'covers'     => $this->storage->usage('covers'),
                'backups'    => $this->storage->usage('backups'),
            ],
            'counts'   => $counts,
            'missing'  => array_slice($missing, 0, 25),
            'renderer' => $this->covers->renderer(),
            'root'     => $this->config->get('storage.root'),
            'graceDays' => (int) $this->config->get('storage.quarantine_days', 7),
        ]);
    }
}
