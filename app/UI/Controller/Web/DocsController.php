<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\TranslationService;
use kintai\Core\Services\WikiContentService;
use kintai\Core\Services\WikiSyncService;
use kintai\UI\ViewRenderer;

final class DocsController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly TranslationService $translations,
        private readonly WikiContentService $wikiContent,
        private readonly WikiSyncService $wikiSync,
    ) {}

    /**
     * GET /docs — point d'entrée unique, sans sélecteur de langue : redirige
     * directement vers la page d'accueil du wiki dans la langue courante de
     * l'utilisateur si elle est disponible localement, sinon affiche un
     * message expliquant qu'aucune version locale n'existe pour cette langue
     * (avec un lien vers GitHub, et un bouton de clonage pour le propriétaire).
     */
    public function index(Request $request): Response
    {
        $locale    = $this->translations->getLocale();
        $firstPage = $this->wikiContent->firstPage();

        if ($firstPage !== null && $this->wikiContent->pageExists($locale, $firstPage)) {
            return Response::redirect(route_url('docs.show', ['lang' => $locale, 'page' => $firstPage]));
        }

        $user = $request->getAttribute('auth_user');

        return Response::html($this->view->render('docs.unavailable', [
            'title'         => __('docs'),
            'locale'        => $locale,
            'githubWikiUrl' => $this->wikiContent->githubWikiUrl($locale),
            'isOwner'       => !empty($user['is_admin']),
        ], 'layout.app'));
    }

    public function show(Request $request): Response
    {
        $lang = strtolower((string) $request->param('lang'));
        $page = (string) $request->param('page');

        $toc         = $this->wikiContent->tableOfContents($lang);
        $contentHtml = $this->wikiContent->render($lang, $page);

        $flatItems = [];
        foreach ($toc as $group) {
            foreach ($group['items'] as $item) {
                $flatItems[] = $item;
            }
        }

        $currentIndex = null;
        foreach ($flatItems as $i => $item) {
            if ($item['slug'] === $page) {
                $currentIndex = $i;
                break;
            }
        }

        $otherLanguages = [];
        foreach (WikiContentService::supportedLangs() as $l) {
            if ($l === $lang) {
                continue;
            }
            $otherLanguages[$l] = $this->wikiContent->pageExists($l, $page);
        }

        $user = $request->getAttribute('auth_user');

        return Response::html($this->view->render('docs.show', [
            'title'          => $currentIndex !== null ? $flatItems[$currentIndex]['title'] : __('docs'),
            'lang'           => $lang,
            'page'           => $page,
            'toc'            => $toc,
            'flatItems'      => $flatItems,
            'currentIndex'   => $currentIndex,
            'contentHtml'    => $contentHtml,
            'otherLanguages' => $otherLanguages,
            'githubWikiUrl'  => $this->wikiContent->githubWikiUrl($lang, $page),
            'isOwner'        => !empty($user['is_admin']),
        ], 'layout.app'));
    }

    /**
     * POST /docs/sync — clone (si absent) ou met à jour (si déjà présent) le
     * wiki local `.wiki/` depuis le wiki GitHub. Réservé au propriétaire.
     */
    public function sync(Request $request): Response
    {
        $result = $this->wikiSync->sync();

        return Response::json($result, $result['ok'] ? 200 : 500);
    }
}
