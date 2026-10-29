<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ForbiddenException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\WikiContentService;
use App\Services\WikiSyncService;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class DocsController
{
    public function __construct(
        private readonly ViewFactory $view,
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
        $locale    = app()->getLocale();
        $firstPage = $this->wikiContent->firstPage();

        if ($firstPage !== null && $this->wikiContent->pageExists($locale, $firstPage)) {
            return redirect()->away(route_url('docs.show', ['lang' => $locale, 'page' => $firstPage]));
        }

        $user = $request->attributes->get('auth_user');

        return response($this->view->make('docs.unavailable', [
            'title'         => __('docs'),
            'locale'        => $locale,
            'githubWikiUrl' => $this->wikiContent->githubWikiUrl($locale),
            'isOwner'       => !empty($user['is_admin']),
        ])->render());
    }

    public function show(Request $request): Response
    {
        $lang = strtolower((string) $request->route('lang'));
        $page = (string) $request->route('page');

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

        $user = $request->attributes->get('auth_user');

        return response($this->view->make('docs.show', [
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
        ])->render());
    }

    /**
     * POST /docs/sync — clone (si absent) ou met à jour (si déjà présent) le
     * wiki local `.wiki/` depuis le wiki GitHub. Réservé au propriétaire.
     */
    public function sync(Request $request): Response
    {
        $result = $this->wikiSync->sync();

        return response()->json($result, $result['ok'] ? 200 : 500);
    }
}
