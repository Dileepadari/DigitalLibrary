<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Shared plumbing for the web controllers: render a view, redirect, and bounce
 * a failed form back to itself with its errors and the values the user typed.
 */
abstract class Controller
{
    public function __construct(
        protected readonly View $view,
        protected readonly Session $session,
    ) {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function success(string $message, string $to): Response
    {
        $this->session->flash('success', $message);

        return $this->redirect($to);
    }

    protected function failure(string $message, string $to): Response
    {
        $this->session->flash('error', $message);

        return $this->redirect($to);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $old
     */
    protected function backWithErrors(string $to, array $errors, array $old = []): Response
    {
        $this->session->flash('errors', $errors);
        $this->session->flash('old', $this->withoutSecrets($old));

        return $this->redirect($to);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $input): array
    {
        foreach (['password', 'password_confirmation', 'current_password', '_token'] as $key) {
            unset($input[$key]);
        }

        return $input;
    }
}
