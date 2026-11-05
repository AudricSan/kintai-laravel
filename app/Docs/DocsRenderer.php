<?php

namespace App\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;

/**
 * Rend une page du wiki en HTML, en mode sûr : le HTML brut du Markdown est échappé (jamais interprété), les liens
 * `javascript:`, `data:` et autres schémas dangereux sont neutralisés, les liens externes s'ouvrent dans un nouvel onglet
 * sans `opener` ni `referrer`. Les liens internes du wiki (« Page », « Page.md », liens absolus `/Compte/Depot/wiki/Page`)
 * deviennent des liens vers `/docs/{page}` ; une cible qui n'est pas un nom de page valide perd son lien. Les images
 * relatives (jamais synchronisées) sont remplacées par leur texte alternatif.
 */
class DocsRenderer
{
    public function render(string $markdown): string
    {
        // Les liens réécrits sont absolus : l'hôte de l'application est interne, tout autre est externe.
        $host = parse_url(url('/'), PHP_URL_HOST);
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'external_link' => [
                'internal_hosts' => is_string($host) && $host !== '' ? [$host] : [],
                'open_in_new_window' => true,
                'html_class' => 'external-link',
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new ExternalLinkExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            $this->rewrite($event->getDocument());
        }, 10);

        return (string) (new MarkdownConverter($environment))->convert($this->withoutLanguageLine($markdown));
    }

    /**
     * La ligne de choix de langue du wiki (« 🌐 English · Français ») renvoie à des pages qui ne sont pas synchronisées.
     */
    private function withoutLanguageLine(string $markdown): string
    {
        return implode("\n", array_filter(explode("\n", $markdown), fn (string $line): bool => ! str_contains($line, '🌐')));
    }

    private function rewrite(Node $document): void
    {
        $nodes = [];

        foreach ($document->iterator() as $node) {
            if ($node instanceof Link || $node instanceof Image) {
                $nodes[] = $node;
            }
        }

        foreach ($nodes as $node) {
            $url = $node->getUrl();

            if ($node instanceof Image) {
                if (! $this->isAbsolute($url)) {
                    $this->unwrap($node);
                }

                continue;
            }

            if ($this->isAbsolute($url) || str_starts_with($url, '#') || str_starts_with($url, 'mailto:')) {
                continue;
            }

            $slug = DocsStore::slugFromTarget($url);

            if ($slug === null) {
                $this->unwrap($node);

                continue;
            }

            $fragment = str_contains($url, '#') ? '#'.rawurlencode(rawurldecode(substr($url, (int) strpos($url, '#') + 1))) : '';
            $node->setUrl(route('docs.show', ['page' => $slug]).($fragment === '#' ? '' : $fragment));
        }
    }

    /** Adresse avec un schéma ou protocole relatif : laissée telle quelle (les schémas dangereux sont neutralisés au rendu). */
    private function isAbsolute(string $url): bool
    {
        return preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $url) === 1;
    }

    /** Remplace le noeud par son contenu (texte du lien, texte alternatif de l'image). */
    private function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }
}
