<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Models\Language;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\InstanceLocale;
use App\Services\TranslationManagementService;
use App\Http\Controllers\HasBaseUrl;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class LanguageController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewFactory $view,
        private readonly TranslationManagementService $translationManagement,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** GET /admin/languages */
    public function index(Request $request): Response
    {

        return response($this->view->make('system.languages', [
            'title'     => __('languages'),
            'languages' => Language::findAll(),
            // Langue de l'instance (APP_LOCALE) : choisie dans les paramètres de l'instance, plus ici.
            'instance_locale' => InstanceLocale::resolve(),
            'error'     => session('error'),
            'success'   => session('success'),
        ])->render());
    }

    /** POST /admin/languages */
    public function store(Request $request): Response
    {

        $code = strtolower(trim((string) $request->post('code', '')));
        $name = trim((string) $request->post('name', ''));

        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) || $name === '' || mb_strlen($name) > 100) {
            return redirect()->away($this->base() . '/admin/languages')->with('error', 'invalid');
        }

        $existing = Language::findByCode($code);
        Language::put([
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

        return redirect()->away($this->base() . '/admin/languages')->with('success', $existing ? 'updated' : 'created');
    }

    /** POST /admin/languages/{code}/toggle-active */
    public function toggleActive(Request $request): Response
    {

        $code = (string) $request->route('code');
        $lang = Language::findByCode($code);
        if (!$lang) {
            return redirect()->away($this->base() . '/admin/languages')->with('error', 'not_found');
        }
        // La langue de l'instance ne peut être ni désactivée ni supprimée.
        if ($code === InstanceLocale::resolve() && !empty($lang['is_active'])) {
            return redirect()->away($this->base() . '/admin/languages')->with('error', 'default_forbidden');
        }

        Language::toggleActive($code, empty($lang['is_active']));

        $this->auditLogger->log($request, 'language.toggle_active', 'language', null, ['code' => $code]);

        return redirect()->away($this->base() . '/admin/languages')->with('success', 'toggled');
    }

    /** POST /admin/languages/{code}/delete */
    public function destroy(Request $request): Response
    {

        $code = (string) $request->route('code');
        $lang = Language::findByCode($code);
        if (!$lang) {
            return redirect()->away($this->base() . '/admin/languages');
        }
        if ($code === InstanceLocale::resolve()) {
            return redirect()->away($this->base() . '/admin/languages')->with('error', 'delete_default_forbidden');
        }
        if (!empty($lang['is_active']) && count(Language::findAllActive()) <= 1) {
            return redirect()->away($this->base() . '/admin/languages')->with('error', 'delete_last_active_forbidden');
        }

        Language::remove($code);

        $this->auditLogger->log($request, 'language.deleted', 'language', null, [
            'code' => $code,
            'name' => $lang['name'],
        ]);

        return redirect()->away($this->base() . '/admin/languages')->with('success', 'deleted');
    }

    /** GET /admin/languages/{code}/edit */
    public function edit(Request $request): Response
    {

        $code = (string) $request->route('code');
        $lang = Language::findByCode($code);
        if (!$lang) {
            return response($this->view->make('errors.404', ['title' => '404'])->render(), 404);
        }

        $search  = trim((string) $request->query('q', ''));
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        $result = $this->translationManagement->browseKeys($code, $search, $page, $perPage);

        return response($this->view->make('system.language-edit', [
            'title'    => __('edit_translations') . ' — ' . $lang['name'],
            'language' => $lang,
            'items'    => $result['items'],
            'total'    => $result['total'],
            'search'   => $search,
            'page'     => $page,
            'perPage'  => $perPage,
            'error'    => session('error'),
            'success'  => session('success'),
        ])->render());
    }

    /** POST /admin/languages/{code}/edit/save (AJAX) — upsert d'une clé (ajout ou modification). */
    public function saveKey(Request $request): Response
    {

        $code = (string) $request->route('code');
        if (!Language::findByCode($code)) {
            return response()->json(['error' => __('error_unknown_language')], 404);
        }

        $key   = trim((string) $request->post('key', ''));
        $value = (string) $request->post('value', '');
        if ($key === '' || !preg_match('/^[a-z0-9_.]+$/i', $key)) {
            return response()->json(['error' => __('error_invalid_key')], 400);
        }

        $this->translationManagement->saveValue($code, $key, $value);
        $this->auditLogger->log($request, 'translation.updated', 'translation', null, ['locale' => $code, 'key' => $key]);

        return response()->json(['ok' => true]);
    }

    /** POST /admin/languages/{code}/edit/delete (AJAX) — supprime une clé pour cette langue uniquement. */
    public function deleteKey(Request $request): Response
    {

        $code = (string) $request->route('code');
        $key  = trim((string) $request->post('key', ''));
        if ($key === '') {
            return response()->json(['error' => __('error_key_required')], 400);
        }

        $this->translationManagement->deleteValue($code, $key);
        $this->auditLogger->log($request, 'translation.deleted', 'translation', null, ['locale' => $code, 'key' => $key]);

        return response()->json(['ok' => true]);
    }
}
