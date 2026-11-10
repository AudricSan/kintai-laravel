<?php

namespace Kintai\Bundles\PlanningImport\Livewire;

use App\Actions\Employees\CreateEmployee;
use App\Authorization\Authorizer;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\PlanningImport\ImportAccess;
use Kintai\Bundles\PlanningImport\ImportApplier;
use Kintai\Bundles\PlanningImport\ImportException;
use Kintai\Bundles\PlanningImport\ImportPlanner;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Import d'un planning Excel : choix du magasin et du fichier, aperçu (rattachement des noms aux employés, shifts déjà
 * planifiés), confirmation. Le droit `shifts.import` du magasin est revérifié à chaque action ; l'aperçu est contrôlé de
 * nouveau à la confirmation (`ImportApplier`) puisque l'état d'un composant Livewire vient du navigateur.
 */
#[Title('Schedule import')]
class Index extends Component
{
    use ResolvesViews;
    use WithFileUploads;

    #[Url(except: '')]
    public string $store = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /**
     * Lignes de l'aperçu (voir `ImportPlanner`) ; vide hors aperçu.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** Magasin de l'aperçu en cours : une confirmation ne vaut que pour lui. */
    #[Locked]
    public int $previewStore = 0;

    /** @var array{created: int, replaced: int, skipped: int, removed: int}|null */
    public ?array $result = null;

    public string $newName = '';

    public string $newLastName = '';

    public string $newFirstName = '';

    public string $newFuriganaLastName = '';

    public string $newFuriganaFirstName = '';

    public function mount(): void
    {
        $stores = $this->stores();
        abort_if($stores === [], 403);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function stores(): array
    {
        return app(ImportAccess::class)->stores($this->user());
    }

    private function storeId(): int
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        return (int) $this->store;
    }

    public function updatedStore(): void
    {
        $this->reset('file', 'rows', 'previewStore', 'result');
    }

    /**
     * Lit le fichier avec la grille du magasin et prépare l'aperçu.
     */
    public function preview(ImportPlanner $planner): void
    {
        $storeId = $this->storeId();
        $this->result = null;

        $this->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,xlsm,csv,ods']]);

        /** @var TemporaryUploadedFile $file */
        $file = $this->file;

        try {
            $rows = $planner->plan($storeId, (string) $file->getRealPath(), $file->getClientOriginalName());
        } catch (ImportException) {
            $this->addError('file', __('The file cannot be read as a schedule.'));

            return;
        }

        $this->reset('file');

        if ($rows === []) {
            $this->addError('file', __('No shift was found in this file: check the grid in the settings.'));

            return;
        }

        $this->rows = $rows;
        $this->previewStore = $storeId;
    }

    public function cancel(): void
    {
        $this->reset('file', 'rows', 'previewStore', 'result');
    }

    public function confirm(ImportApplier $applier): void
    {
        $storeId = $this->storeId();
        abort_unless($this->rows !== [] && $this->previewStore === $storeId, 403);

        $this->result = $applier->apply($this->user(), $storeId, $this->rows);
        $this->reset('rows', 'previewStore');

        Flux::toast(variant: 'success', text: __('Schedule imported.'));
    }

    /**
     * Ouvre la création d'un employé pour un nom lu dans le fichier.
     */
    public function startCreate(string $name): void
    {
        $this->storeId();
        $this->resetErrorBag();
        $this->newName = $name;
        $this->newLastName = $name;
        $this->newFirstName = '';
        $this->newFuriganaLastName = '';
        $this->newFuriganaFirstName = '';

        Flux::modal('create-import-employee')->show();
    }

    /**
     * Crée l'employé (source « import ») dans le magasin et le rattache à toutes les lignes portant ce nom.
     */
    public function createEmployee(CreateEmployee $action): void
    {
        $storeId = $this->storeId();
        abort_unless($this->previewStore === $storeId, 403);

        try {
            $employee = $action->handle($this->user(), [
                'last_name' => $this->newLastName,
                'first_name' => $this->newFirstName,
                'furigana_last_name' => $this->newFuriganaLastName,
                'furigana_first_name' => $this->newFuriganaFirstName,
                'store_id' => $storeId,
            ], 'import');
        } catch (AuthorizationException) {
            $this->addError('newLastName', __('You cannot create employees in this store.'));

            return;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        foreach ($this->rows as $i => $row) {
            if (($row['staff_name'] ?? null) === $this->newName && (int) ($row['user_id'] ?? 0) === 0) {
                $this->rows[$i]['user_id'] = $employee->id;
                $this->rows[$i]['skip'] = (bool) ($row['exists'] ?? false);
            }
        }

        Flux::modal('create-import-employee')->close();
    }

    public function render(): View
    {
        $stores = $this->stores();
        $access = app(ImportAccess::class);

        return app('view')->make($this->viewName('index'), [
            'stores' => $stores,
            'members' => $this->rows === [] ? [] : $access->members($this->previewStore),
            'canCreate' => app(Authorizer::class)->allows($this->user(), 'employees.create', (int) $this->store),
        ]);
    }
}
