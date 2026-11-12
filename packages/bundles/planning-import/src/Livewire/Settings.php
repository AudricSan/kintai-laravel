<?php

namespace Kintai\Bundles\PlanningImport\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\PlanningImport\Detection\GridSettings;
use Kintai\Bundles\PlanningImport\ImportAccess;
use Kintai\Bundles\PlanningImport\Models\StoreImportSetting;
use Kintai\Contracts\Activity\ActivityRecorder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Réglages d'import d'un magasin (`shifts.import` sur le magasin) : grille du fichier Excel (colonnes, heure de départ,
 * minutes par colonne, taille des blocs, lignes de shifts, motif des feuilles) et pause automatique.
 */
#[Title('Schedule import settings')]
class Settings extends Component
{
    use ResolvesViews;

    #[Url(except: '')]
    public string $store = '';

    public int $colStart = 4;

    public int $colEnd = 52;

    public int $baseHour = 6;

    public int $minutesPerCol = 30;

    public int $blockSize = 9;

    public int $shiftRows = 7;

    public string $sheetPattern = GridSettings::DEFAULT_PATTERN;

    public int $autoPauseAfter = 0;

    public int $autoPause = 30;

    public function mount(): void
    {
        $stores = $this->stores();
        abort_if($stores === [], 403);

        $this->store = ctype_digit($this->store) && isset($stores[(int) $this->store]) ? $this->store : (string) array_key_first($stores);
        $this->load();
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

    public function updatedStore(): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $this->resetErrorBag();
        $this->load();
    }

    private function load(): void
    {
        $grid = GridSettings::forStore((int) $this->store);

        $this->colStart = $grid->colStart;
        $this->colEnd = $grid->colEnd;
        $this->baseHour = $grid->baseHour;
        $this->minutesPerCol = $grid->minutesPerCol;
        $this->blockSize = $grid->blockSize;
        $this->shiftRows = $grid->shiftRows;
        $this->sheetPattern = $grid->sheetPattern;
        $this->autoPauseAfter = $grid->autoPauseAfterMinutes;
        $this->autoPause = $grid->autoPauseMinutes;
    }

    public function save(ActivityRecorder $activity): void
    {
        abort_unless(isset($this->stores()[(int) $this->store]), 403);

        $data = $this->validate([
            'colStart' => ['required', 'integer', 'between:1,200'],
            'colEnd' => ['required', 'integer', 'between:1,200', 'gte:colStart'],
            'baseHour' => ['required', 'integer', 'between:0,23'],
            'minutesPerCol' => ['required', 'integer', 'between:1,240'],
            'blockSize' => ['required', 'integer', 'between:2,100'],
            'shiftRows' => ['required', 'integer', 'between:1,99'],
            'sheetPattern' => ['required', 'string', 'max:255'],
            'autoPauseAfter' => ['required', 'integer', 'between:0,1440'],
            'autoPause' => ['required', 'integer', 'between:0,1440'],
        ]);

        if (GridSettings::regex($data['sheetPattern']) === null) {
            throw ValidationException::withMessages(['sheetPattern' => __('This pattern is not a valid regular expression.')]);
        }

        StoreImportSetting::query()->updateOrCreate(['store_id' => (int) $this->store], [
            'col_start' => $data['colStart'],
            'col_end' => $data['colEnd'],
            'base_hour' => $data['baseHour'],
            'minutes_per_col' => $data['minutesPerCol'],
            'block_size' => $data['blockSize'],
            'shift_rows' => $data['shiftRows'],
            'sheet_filter_pattern' => $data['sheetPattern'],
            'auto_pause_after_minutes' => $data['autoPauseAfter'],
            'auto_pause_minutes' => $data['autoPause'],
        ]);

        $activity->record('planning_import.settings_updated', null, (int) $this->store, [], $this->user());

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('settings'), ['stores' => $this->stores()]);
    }
}
