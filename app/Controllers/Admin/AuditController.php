<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AuditLogRepository;

final class AuditController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuditLogRepository $audit,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return $this->render('pages/admin/audit', [
            'results' => $this->audit->paginate($filters, (int) $request->query('page', 1), 50),
            'filters' => $filters,
            'actions' => $this->audit->actions(),
        ]);
    }

    /**
     * The same rows as the page, as CSV. Built in memory rather than streamed:
     * the export is capped, and a log big enough to need streaming needs a
     * database export instead.
     */
    public function export(Request $request): Response
    {
        $rows = $this->audit->export($this->filters($request));
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->failure('Could not build the export.', '/admin/audit');
        }

        fputcsv($handle, ['id', 'when', 'actor', 'action', 'subject_type', 'subject_id', 'before', 'after']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['created_at'],
                $row['actor_username'] ?? '',
                $row['action'],
                $row['subject_type'] ?? '',
                $row['subject_id'] ?? '',
                $row['before_state'] ?? '',
                $row['after_state'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-' . date('Y-m-d') . '.csv"',
            'Content-Length'      => (string) strlen($csv),
        ]);
    }

    /** @return array{actor: string, action: string, subject: string} */
    private function filters(Request $request): array
    {
        return [
            'actor'   => trim((string) $request->query('actor', '')),
            'action'  => trim((string) $request->query('action', '')),
            'subject' => trim((string) $request->query('subject', '')),
        ];
    }
}
