<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\TranslationManagementService;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class LanguageController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly LanguageRepositoryInterface $languages,
        private readonly TranslationManagementService $translationManagement,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** GET /admin/languages */
    public function index(Request $request): Response
    {

        return Response::html($this->view->render('system.languages', [
            'title'     => __('languages'),
            'languages' => $this->languages->findAll(),
            // Langue de l'instance (APP_LOCALE) : choisie dans les paramètres de l'instance, plus ici.
            'instance_locale' => InstanceLocale::resolve($this->languages),
            'error'     => $request->query('error'),
            'success'   => $request->query('success'),
        ], 'layout.app'));
    }

    /** POST /admin/languages */
    public function store(Request $request): Response
    {

        $code = strtolower(trim((string) $request->post('code', '')));
        $name = trim((string) $request->post('name', ''));

        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) || $name === '' || mb_strlen($name) > 100) {
            return Response::redirect($this->base() . '/admin/languages?error=invalid');
        }

        $existing = $this->languages->findByCode($code);
        $this->languages->save([
            'code'      => $code,
            'name'      => $name,
            'is_active' => $request->post('is_active', '1') === '1',
        ]);

        $this->auditLogger->log(
            $request,
            $existing ? 'language.updated' : 'language.created',
            'language',
            null,
            ['code' => $code, 'name' => $name],
        );

        return Response::redirect($this->base() . '/admin/languages?success=' . ($existing ? 'updated' : 'created'));
    }

    /** POST /admin/languages/{code}/toggle-active */
    public function toggleActive(Request $request): Response
    {

        $code = (string) $request->param('code');
        $lang = $this->languages->findByCode($code);
        if (!$lang) {
            return Response::redirect($this->base() . '/admin/languages?error=not_found');
        }
        // La langue de l'instance ne peut être ni désactivée ni supprimée.
        if ($code === InstanceLocale::resolve($this->languages) && !empty($lang['is_active'])) {
            return Response::redirect($this->base() . '/admin/languages?error=default_forbidden');
        }

        $this->languages->toggleActive($code, empty($lang['is_active']));

        $this->auditLogger->log($request, 'language.toggle_active', 'language', null, ['code' => $code]);

        return Response::redirect($this->base() . '/admin/languages?success=toggled');
    }

    /** POST /admin/languages/{code}/delete */
    public function destroy(Request $request): Response
    {

        $code = (string) $request->param('code');
        $lang = $this->languages->findByCode($code);
        if (!$lang) {
            return Response::redirect($this->base() . '/admin/languages');
        }
        if ($code === InstanceLocale::resolve($this->languages)) {
            return Response::redirect($this->base() . '/admin/languages?error=delete_default_forbidden');
        }
        if (!empty($lang['is_active']) && count($this->languages->findAllActive()) <= 1) {
            return Response::redirect($this->base() . '/admin/languages?error=delete_last_active_forbidden');
        }

        $this->languages->delete($code);

        $this->auditLogger->log($request, 'language.deleted', 'language', null, [
            'code' => $code,
            'name' => $lang['name'],
        ]);

        return Response::redirect($this->base() . '/admin/languages?success=deleted');
    }

    /** GET /admin/languages/{code}/edit */
    public function edit(Request $request): Response
    {

        $code = (string) $request->param('code');
        $lang = $this->languages->findByCode($code);
        if (!$lang) {
            return Response::html($this->view->render('errors.404', ['title' => '404'], 'layout.app'), 404);
        }

        $search  = trim((string) $request->query('q', ''));
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        $result = $this->translationManagement->browseKeys($code, $search, $page, $perPage);

        return Response::html($this->view->render('system.language-edit', [
            'title'    => __('edit_translations') . ' — ' . $lang['name'],
            'language' => $lang,
            'items'    => $result['items'],
            'total'    => $result['total'],
            'search'   => $search,
            'page'     => $page,
            'perPage'  => $perPage,
            'error'    => $request->query('error'),
            'success'  => $request->query('success'),
        ], 'layout.app'));
    }

    /** POST /admin/languages/{code}/edit/save (AJAX) — upsert d'une clé (ajout ou modification). */
    public function saveKey(Request $request): Response
    {

        $code = (string) $request->param('code');
        if (!$this->languages->findByCode($code)) {
            return Response::json(['error' => __('error_unknown_language')], 404);
        }

        $key   = trim((string) $request->post('key', ''));
        $value = (string) $request->post('value', '');
        if ($key === '' || !preg_match('/^[a-z0-9_.]+$/i', $key)) {
            return Response::json(['error' => __('error_invalid_key')], 400);
        }

        $this->translationManagement->saveValue($code, $key, $value);
        $this->auditLogger->log($request, 'translation.updated', 'translation', null, ['locale' => $code, 'key' => $key]);

        return Response::json(['ok' => true]);
    }

    /** POST /admin/languages/{code}/edit/delete (AJAX) — supprime une clé pour cette langue uniquement. */
    public function deleteKey(Request $request): Response
    {

        $code = (string) $request->param('code');
        $key  = trim((string) $request->post('key', ''));
        if ($key === '') {
            return Response::json(['error' => __('error_key_required')], 400);
        }

        $this->translationManagement->deleteValue($code, $key);
        $this->auditLogger->log($request, 'translation.deleted', 'translation', null, ['locale' => $code, 'key' => $key]);

        return Response::json(['ok' => true]);
    }
}
