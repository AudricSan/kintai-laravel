<?php

use App\Actions\Support\ReportIssue;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Report a problem')] class extends Component {
    public string $title = '';

    public string $description = '';

    /** Ajoute la version de Kintai et le navigateur. */
    public bool $includeTechnical = true;

    /** Ajoute le nom de l'utilisateur : jamais par défaut, le signalement est public. */
    public bool $includeIdentity = false;

    /** Adresse de l'issue créée, montrée après l'envoi. */
    #[Locked]
    public string $issueUrl = '';

    public function submit(ReportIssue $report): void
    {
        $this->resetErrorBag();
        $this->issueUrl = '';

        $result = $report->handle(Auth::user(), $this->title, $this->description, $this->includeTechnical, $this->includeIdentity);

        if (! $result['created']) {
            // Pas de jeton GitHub (ou création impossible) : formulaire de GitHub pré-rempli.
            $this->redirect($result['url']);

            return;
        }

        $this->issueUrl = $result['url'];
        $this->reset('title', 'description');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('Report a problem') }}</flux:heading>
        <flux:subheading>{{ __('Describe what went wrong or what is missing. The report is sent to the project on GitHub, where it is public: do not write passwords or personal data.') }}</flux:subheading>
    </div>

    @if ($issueUrl !== '')
        <flux:callout variant="success" icon="check-circle" data-test="issue-created">
            <flux:callout.heading>{{ __('Thank you, your report was sent.') }}</flux:callout.heading>
            <flux:callout.text>
                <flux:link :href="$issueUrl" external data-test="issue-link">{{ __('See the report on GitHub') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif

    @error('report')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="report-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <form wire:submit="submit" class="space-y-4" data-test="report-form">
        <flux:input wire:model="title" :label="__('Title')" maxlength="200" required data-test="issue-title" />
        <flux:textarea wire:model="description" :label="__('Description')" rows="8" maxlength="4000" required data-test="issue-description" />
        <flux:checkbox wire:model="includeTechnical" :label="__('Add technical information (Kintai version and browser)')" data-test="issue-technical" />
        <flux:checkbox wire:model="includeIdentity" :label="__('Add my name to the report')" data-test="issue-identity" />
        <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="issue-submit">{{ __('Send the report') }}</flux:button>
    </form>
</section>
