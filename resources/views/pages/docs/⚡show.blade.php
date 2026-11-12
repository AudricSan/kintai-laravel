<?php

use App\Actions\Docs\SyncDocs;
use App\Docs\DocsRenderer;
use App\Docs\DocsStore;
use App\Settings\AppSettings;
use App\Support\GithubRepository;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation')] class extends Component {
    /** Nom de la page affichée ('' = page d'accueil de la documentation). */
    #[Locked]
    public string $page = '';

    public function mount(string $page = ''): void
    {
        $store = app(DocsStore::class);

        if ($page === '') {
            $first = $store->firstPage();

            if ($first !== null) {
                $this->redirectRoute('docs.show', ['page' => $first], navigate: true);
            }

            return;
        }

        // Nom invalide (chemin, « .. »…) ou page inconnue : 404, jamais d'accès disque hors du dossier de la documentation.
        // Tant que rien n'est synchronisé, c'est la page « documentation indisponible » qui répond.
        abort_unless(DocsStore::isValidSlug($page), 404);
        abort_if($store->isSynced() && ! $store->has($page), 404);

        $this->page = $page;
    }

    public function sync(SyncDocs $sync): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $result = $sync->handle(Auth::user());

        if ($result['ok']) {
            Flux::toast(variant: 'success', text: __('Documentation synchronized.'));
        } else {
            Flux::toast(variant: 'danger', text: __('The documentation could not be synchronized. Check the connection to GitHub and the repository setting.'));
        }

        // Le contenu affiché a pu changer : la page est rendue de nouveau avec le nouveau contenu.
        if ($this->page !== '' && ! app(DocsStore::class)->has($this->page)) {
            $this->redirectRoute('docs.show', navigate: true);
        }
    }

    public function with(): array
    {
        $store = app(DocsStore::class);
        $synced = $store->isSynced();
        $markdown = $synced && $this->page !== '' ? $store->read($this->page) : null;

        return [
            'synced' => $synced,
            'toc' => $synced ? $store->tableOfContents() : [],
            'html' => $markdown === null ? '' : app(DocsRenderer::class)->render($markdown),
            'canSync' => Gate::allows('manage-settings'),
            'lastSync' => app(AppSettings::class)->docsLastSyncAt()?->timezone((string) config('kintai.timezone')),
            'wikiUrl' => GithubRepository::isValid((string) config('kintai.docs.repository'))
                ? 'https://github.com/'.config('kintai.docs.repository').'/wiki'
                : null,
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Documentation') }}</flux:heading>
            @if ($lastSync)
                <flux:subheading data-test="docs-last-sync">{{ __('Last synchronized: :date', ['date' => $lastSync->format('Y-m-d H:i')]) }}</flux:subheading>
            @endif
        </div>
        @if ($canSync)
            <flux:button icon="arrow-path" wire:click="sync" wire:loading.attr="disabled" data-test="docs-sync">{{ __('Synchronize') }}</flux:button>
        @endif
    </div>

    @if (! $synced)
        <flux:callout icon="book-open" data-test="docs-unavailable">
            <flux:callout.heading>{{ __('The documentation is not available yet.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('It has to be downloaded from the project wiki first.') }}
                @if ($canSync)
                    {{ __('Use the Synchronize button.') }}
                @else
                    {{ __('Ask the Owner of the instance to synchronize it.') }}
                @endif
                @if ($wikiUrl)
                    <flux:link :href="$wikiUrl" external>{{ __('Read it online') }}</flux:link>
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="flex items-start gap-8 max-md:flex-col">
            <nav class="w-full shrink-0 md:w-64" aria-label="{{ __('Documentation') }}" data-test="docs-toc">
                @foreach ($toc as $group)
                    <div class="mb-4">
                        @if ($group['label'])
                            <flux:heading class="mb-1">{{ $group['label'] }}</flux:heading>
                        @endif
                        <flux:navlist>
                            @foreach ($group['items'] as $item)
                                <flux:navlist.item :href="route('docs.show', ['page' => $item['slug']])" :current="$item['slug'] === $page" wire:navigate>{{ $item['title'] }}</flux:navlist.item>
                            @endforeach
                        </flux:navlist>
                    </div>
                @endforeach
            </nav>

            <article class="docs-content min-w-0 flex-1" data-test="docs-content">
                @if ($page === '')
                    <flux:text>{{ __('Choose a page in the list.') }}</flux:text>
                @else
                    {!! $html !!}
                @endif
            </article>
        </div>
    @endif
</section>
