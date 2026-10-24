<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\GithubIssueService;
use kintai\Core\Services\UpdateService;

/**
 * "Signaler un problème" (footer) : ouvre une issue directement sur le dépôt
 * GitHub du projet depuis un petit formulaire titre + description, sans faire
 * quitter l'application (voir GithubIssueService). Le signalement ne contient
 * jamais l'identité du déclarant : c'est un ticket public.
 */
final class SupportController
{
    use HasBaseUrl;

    private const MAX_TITLE_LENGTH = 200;
    private const MAX_DESCRIPTION_LENGTH = 4000;

    public function __construct(
        private readonly GithubIssueService $issues,
        private readonly AuditLogger $auditLogger,
        private readonly UpdateService $updateService,
    ) {}

    /** POST /support/report-issue */
    public function reportIssue(Request $request): Response
    {
        $returnTo = safe_redirect_path((string) $request->post('return_to', ''), $this->base() . '/');

        $title = trim((string) $request->post('title', ''));
        $description = trim((string) $request->post('description', ''));

        if ($title === '' || $description === '') {
            return Response::redirect($returnTo . '?ri_error=empty');
        }

        $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
        $description = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);

        $body = $description
            . "\n\n---\n"
            . "_Signalé depuis l'application Kintai (v" . $this->updateService->getCurrentVersion() . ")._";

        $result = $this->issues->isConfigured()
            ? $this->issues->createIssue($title, $body)
            : ['ok' => false, 'error' => 'not_configured'];

        $user = $request->getAttribute('auth_user') ?? [];

        if ($result['ok']) {
            $this->auditLogger->log(
                $request,
                'support.issue_reported',
                'github_issue',
                null,
                ['title' => $title, 'issue_url' => $result['issue_url']],
                null,
                (int) ($user['id'] ?? 0)
            );

            return Response::redirect($returnTo . '?ri_success=' . urlencode($result['issue_url']));
        }

        if ($result['error'] === 'not_configured') {
            // Pas de jeton configuré sur cette instance : on se rabat sur un lien
            // GitHub pré-rempli plutôt que de bloquer le signalement.
            return Response::redirect($this->issues->fallbackUrl($title, $description));
        }

        return Response::redirect($returnTo . '?ri_error=send_failed');
    }
}
