<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\SettingsRepository;
use App\Services\Auth;

/**
 * The site settings an admin can change without a deploy.
 *
 * Each field is declared here with its type, so the form, the validation and
 * the write all come from one list rather than three that can disagree.
 */
final class SettingsController extends Controller
{
    /** @var array<string, array{label: string, type: string, hint: string, options?: array<string, string>}> */
    private const FIELDS = [
        'site.name' => [
            'label' => 'Library name',
            'type'  => 'text',
            'hint'  => 'Shown in the header, the page title and every email.',
        ],
        'site.tagline' => [
            'label' => 'Tagline',
            'type'  => 'text',
            'hint'  => 'One line under the name on the home page.',
        ],
        'registration.mode' => [
            'label'   => 'Registration',
            'type'    => 'choice',
            'hint'    => 'Closed still lets the first account be created, so an empty install can be set up.',
            'options' => ['open' => 'Anyone may join', 'closed' => 'Closed'],
        ],
        'uploads.require_licence_evidence' => [
            'label' => 'Reviewers must confirm the licence basis',
            'type'  => 'bool',
            'hint'  => 'Puts the licence in front of the reviewer before they can approve an upload.',
        ],
        'uploads.max_bytes' => [
            'label' => 'Largest file accepted (bytes)',
            'type'  => 'int',
            'hint'  => 'The web server has its own limit; this cannot exceed it.',
        ],
        'uploads.default_quota' => [
            'label' => 'Upload quota for a new account (bytes)',
            'type'  => 'int',
            'hint'  => 'Existing accounts keep the quota they have.',
        ],
        'features.reviews' => [
            'label' => 'Reviews and ratings',
            'type'  => 'bool',
            'hint'  => 'Turning this off hides the review section everywhere.',
        ],
        'features.requests' => [
            'label' => 'Book requests',
            'type'  => 'bool',
            'hint'  => 'Turning this off hides the request list and the ask form.',
        ],
        'site.maintenance' => [
            'label' => 'Maintenance mode',
            'type'  => 'bool',
            'hint'  => 'Everyone but an admin sees a notice instead of the site.',
        ],
        'site.maintenance_message' => [
            'label' => 'Maintenance notice',
            'type'  => 'text',
            'hint'  => 'What to tell people while the site is closed.',
        ],
    ];

    public function __construct(
        View $view,
        Session $session,
        private readonly SettingsRepository $settings,
        private readonly AuditLogRepository $audit,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function edit(Request $request): Response
    {
        return $this->render('pages/admin/settings', [
            'fields' => self::FIELDS,
            'values' => $this->settings->all(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $before = [];
        $after = [];

        foreach (self::FIELDS as $key => $field) {
            $value = $this->cast($field['type'], $request->input($this->fieldName($key)));
            $current = $this->settings->get($key);

            if ($value === $current) {
                continue;
            }

            $this->settings->set($key, $value);
            $before[$key] = $current;
            $after[$key] = $value;
        }

        if ($after === []) {
            return $this->success('Nothing changed.', '/admin/settings');
        }

        // Settings are a lever on everyone's experience, so who moved one and
        // when is worth keeping.
        $this->audit->record($user->id, 'settings.updated', 'settings', null, $before, $after);

        return $this->success(count($after) . ' setting(s) saved.', '/admin/settings');
    }

    /** A dotted key is not a valid HTML name, so the form uses underscores. */
    private function fieldName(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool'  => $value !== null && (string) $value !== '' && (string) $value !== '0',
            'int'   => max(0, (int) $value),
            default => trim((string) ($value ?? '')),
        };
    }
}
