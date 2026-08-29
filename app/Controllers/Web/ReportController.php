<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\BookRepository;
use App\Repositories\TakedownRepository;
use App\Services\Auth;

/**
 * The public takedown form.
 *
 * No account required: a rights holder should not have to join a library to ask
 * it to stop hosting their book. See PLAN.md section 9.
 */
final class ReportController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TakedownRepository $takedowns,
        private readonly BookRepository $books,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function create(Request $request): Response
    {
        $slug = trim((string) $request->query('book', ''));

        return $this->render('pages/report', [
            'book' => $slug === '' ? null : $this->books->findBySlug($slug),
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'claimant_name'  => 'required|min:2|max:160',
            'claimant_email' => 'required|email|max:191',
            'claimant_role'  => 'max:160',
            'basis'          => 'required|min:20|max:2000',
            'subject_url'    => 'max:500',
        ], [
            'claimant_name'  => 'name',
            'claimant_email' => 'email address',
            'basis'          => 'explanation',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/report', $validator->errors(), $request->all());
        }

        $slug = trim((string) $request->input('book_slug', ''));
        $book = $slug === '' ? null : $this->books->findBySlug($slug, false);

        $this->takedowns->create([
            'book_id'        => $book?->id,
            'subject_url'    => $this->nullable($request->input('subject_url')),
            'claimant_name'  => (string) $request->input('claimant_name'),
            'claimant_email' => (string) $request->input('claimant_email'),
            'claimant_role'  => $this->nullable($request->input('claimant_role')),
            'basis'          => (string) $request->input('basis'),
            'ip_hash'        => $this->auth->ipHash($request->ip()),
        ]);

        return $this->success(
            'Received. An administrator looks at every notice, and you will hear back at the address you gave.',
            '/report'
        );
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
