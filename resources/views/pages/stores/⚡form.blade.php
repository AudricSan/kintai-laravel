<?php

use App\Actions\Stores\AddStoreMember;
use App\Actions\Stores\CreateStore;
use App\Actions\Stores\DeleteStore;
use App\Actions\Stores\RemoveStoreMember;
use App\Actions\Stores\UpdateStore;
use App\Actions\Stores\UpdateStoreMember;
use App\Authorization\Authorizer;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use App\Stores\StoreFeatureRegistry;
use App\Support\Locales;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Store')] class extends Component {
    #[Locked]
    public ?int $storeId = null;

    public string $code = '';

    public string $name = '';

    public string $timezone = '';

    public string $currency = 'EUR';

    public string $currency_symbol_style = 'kanji';

    public string $locale = '';

    public string $email = '';

    public string $phone = '';

    public string $address_street = '';

    public string $address_city = '';

    public string $address_postal = '';

    public string $address_country = '';

    public bool $is_active = true;

    public string $week_start_day = '1';

    public string $low_staff_hour_start = '';

    public string $low_staff_hour_end = '';

    public string $min_staff_per_day = '0';

    public string $min_shift_minutes = '120';

    public string $max_shift_minutes = '480';

    /** @var array<string, bool> clé de fonctionnalité => activée */
    public array $features = [];

    /** @var array<string, string> utilisateur, rôle et données du membre à ajouter */
    public array $add = ['user_id' => '', 'role_id' => '', 'staff_code' => '', 'hire_date' => '', 'contract_type' => ''];

    public string $candidateSearch = '';

    /** @var array<string, mixed> fiche du membre en cours de modification */
    public array $member = ['user_id' => 0, 'staff_code' => '', 'hire_date' => '', 'contract_type' => '', 'is_active' => true, 'role_id' => ''];

    #[Locked]
    public int $removalUserId = 0;

    public function mount(?Store $store = null): void
    {
        $authorizer = app(Authorizer::class);

        if ($store === null || ! $store->exists) {
            abort_unless($authorizer->hasUnrestricted(Auth::user(), 'stores.create'), 403);
            $this->timezone = (string) config('app.timezone');
            $this->features = array_fill_keys(app(StoreFeatureRegistry::class)->keys(), true);
            $this->add['role_id'] = $this->defaultRoleId();

            return;
        }

        abort_unless($authorizer->allows(Auth::user(), 'stores.view', $store), 403);

        $this->storeId = $store->id;
        $this->fillFromStore($store);
        $this->add['role_id'] = $this->defaultRoleId();
    }

    private function defaultRoleId(): string
    {
        return (string) (Role::query()->where('slug', 'employee')->value('id') ?? '');
    }

    private function store(): ?Store
    {
        return $this->storeId === null ? null : Store::query()->findOrFail($this->storeId);
    }

    private function fillFromStore(Store $store): void
    {
        $this->code = $store->code;
        $this->name = $store->name;
        $this->timezone = $store->timezone;
        $this->currency = $store->currency;
        $this->currency_symbol_style = $store->currency_symbol_style;
        $this->locale = $store->locale ?? '';
        $this->email = $store->email ?? '';
        $this->phone = $store->phone ?? '';
        $this->address_street = $store->address_street ?? '';
        $this->address_city = $store->address_city ?? '';
        $this->address_postal = $store->address_postal ?? '';
        $this->address_country = $store->address_country ?? '';
        $this->is_active = $store->is_active;
        $this->week_start_day = (string) $store->week_start_day;
        $this->low_staff_hour_start = $store->low_staff_hour_start === null ? '' : (string) $store->low_staff_hour_start;
        $this->low_staff_hour_end = $store->low_staff_hour_end === null ? '' : (string) $store->low_staff_hour_end;
        $this->min_staff_per_day = (string) $store->min_staff_per_day;
        $this->min_shift_minutes = (string) $store->min_shift_minutes;
        $this->max_shift_minutes = (string) $store->max_shift_minutes;

        $this->features = [];
        foreach (app(StoreFeatureRegistry::class)->keys() as $key) {
            $this->features[$key] = $store->hasFeature($key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function storeInput(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'currency_symbol_style' => $this->currency_symbol_style,
            'locale' => $this->locale,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_street' => $this->address_street,
            'address_city' => $this->address_city,
            'address_postal' => $this->address_postal,
            'address_country' => $this->address_country,
            'is_active' => $this->is_active,
            'week_start_day' => $this->week_start_day,
            'low_staff_hour_start' => $this->low_staff_hour_start,
            'low_staff_hour_end' => $this->low_staff_hour_end,
            'min_staff_per_day' => $this->min_staff_per_day,
            'min_shift_minutes' => $this->min_shift_minutes,
            'max_shift_minutes' => $this->max_shift_minutes,
            'features' => $this->features,
        ];
    }

    public function save(CreateStore $createStore, UpdateStore $updateStore): void
    {
        $store = $this->store();

        if ($store === null) {
            $created = $createStore->handle(Auth::user(), $this->storeInput());
            $this->redirect(route('stores.edit', $created), navigate: true);

            return;
        }

        $updateStore->handle(Auth::user(), $store, $this->storeInput());

        $this->fillFromStore($store->refresh());
        Flux::toast(variant: 'success', text: __('Store saved.'));
    }

    public function delete(DeleteStore $deleteStore): void
    {
        $store = $this->store();
        abort_if($store === null, 404);

        $deleteStore->handle(Auth::user(), $store);

        $this->redirect(route('stores.index'), navigate: true);
    }

    /**
     * Les erreurs d'une action sont préfixées pour s'afficher dans le bon formulaire de la page.
     */
    private function prefixErrors(string $prefix, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$prefix.'.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    public function addMember(AddStoreMember $addMember): void
    {
        $store = $this->store();
        abort_if($store === null, 404);
        $this->resetErrorBag();

        $this->prefixErrors('add', fn () => $addMember->handle(Auth::user(), $store, [
            'user_id' => $this->add['user_id'],
            'role_id' => $this->add['role_id'],
            'staff_code' => $this->add['staff_code'],
            'hire_date' => $this->add['hire_date'],
            'contract_type' => $this->add['contract_type'],
        ]));

        $this->reset('add', 'candidateSearch');
        $this->add['role_id'] = $this->defaultRoleId();
        Flux::toast(variant: 'success', text: __('Member added.'));
    }

    public function editMember(int $userId): void
    {
        $store = $this->store();
        abort_if($store === null, 404);
        $this->resetErrorBag();

        $membership = $store->members()->where('users.id', $userId)->firstOrFail();
        $roleId = RoleAssignment::query()->where('user_id', $userId)->where('store_id', $store->id)->orderBy('id')->value('role_id');

        $this->member = [
            'user_id' => $userId,
            'staff_code' => $membership->pivot->staff_code ?? '',
            'hire_date' => $membership->pivot->hire_date?->format('Y-m-d') ?? '',
            'contract_type' => $membership->pivot->contract_type ?? '',
            'is_active' => $membership->pivot->is_active,
            'role_id' => $roleId === null ? '' : (string) $roleId,
        ];

        Flux::modal('edit-member')->show();
    }

    public function saveMember(UpdateStoreMember $updateMember): void
    {
        $store = $this->store();
        abort_if($store === null, 404);
        $this->resetErrorBag();

        $target = User::query()->findOrFail((int) $this->member['user_id']);

        $this->prefixErrors('member', fn () => $updateMember->handle(Auth::user(), $store, $target, [
            'staff_code' => $this->member['staff_code'],
            'hire_date' => $this->member['hire_date'],
            'contract_type' => $this->member['contract_type'],
            'is_active' => $this->member['is_active'],
            'role_id' => $this->member['role_id'],
        ]));

        Flux::modal('edit-member')->close();
        Flux::toast(variant: 'success', text: __('Member saved.'));
    }

    public function confirmRemoval(int $userId): void
    {
        $this->resetErrorBag();
        $this->removalUserId = $userId;

        Flux::modal('confirm-member-removal')->show();
    }

    public function removeMember(RemoveStoreMember $removeMember): void
    {
        $store = $this->store();
        abort_if($store === null, 404);
        $this->resetErrorBag();

        $target = User::query()->findOrFail($this->removalUserId);

        $this->prefixErrors('removal', fn () => $removeMember->handle(Auth::user(), $store, $target));

        $this->removalUserId = 0;
        Flux::modal('confirm-member-removal')->close();
        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    public function with(): array
    {
        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $store = $this->store();

        $data = [
            'store' => $store,
            'locales' => Locales::SUPPORTED,
            'featureLabels' => app(StoreFeatureRegistry::class)->all(),
            'canUpdate' => $store === null || $authorizer->allows($actor, 'stores.update', $store),
            'canDelete' => $store !== null && $authorizer->hasUnrestricted($actor, 'stores.delete'),
            'canManageMembers' => false,
            'members' => collect(),
            'memberRoles' => collect(),
            'candidates' => collect(),
        ];

        if ($store === null) {
            return $data;
        }

        abort_unless($authorizer->allows($actor, 'stores.view', $store), 403);

        if (! $authorizer->allows($actor, 'employees.update', $store)) {
            return $data;
        }

        $assignable = Role::query()->where('is_system', false)->orderBy('name')->get()
            ->filter(fn (Role $role): bool => $authorizer->canAssignRole($actor, $role, $store))
            ->values();

        $roleNames = RoleAssignment::query()
            ->where('store_id', $store->id)
            ->with('role:id,name')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($assignments): string => $assignments->map(fn ($assignment) => $assignment->role?->name)->filter()->join(', '));

        $term = trim($this->candidateSearch);
        $candidates = collect();
        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $candidates = User::query()
                ->where('is_active', true)
                ->whereDoesntHave('stores', fn (Builder $q) => $q->where('stores.id', $store->id))
                ->where(function (Builder $q) use ($like): void {
                    foreach (['name', 'email', 'employee_code'] as $column) {
                        $q->orWhereRaw("{$column} like ? escape '!'", [$like]);
                    }
                })
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'employee_code'])
                ->reject(fn (User $user): bool => $authorizer->isOwner($user) && ! $authorizer->isOwner($actor))
                ->values();
        }

        return [
            'canManageMembers' => true,
            'members' => $store->members()->orderBy('users.name')->get(),
            'roleNames' => $roleNames,
            'memberRoles' => $assignable,
            'candidates' => $candidates,
        ] + $data;
    }
}; ?>

<section class="w-full max-w-4xl space-y-10 p-4">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ $store ? $store->name : __('New store') }}</flux:heading>
        <flux:button :href="route('stores.index')" wire:navigate variant="filled">{{ __('Back to stores') }}</flux:button>
    </div>

    @if (! $canUpdate)
        <flux:callout icon="lock-closed" data-test="read-only-notice">
            <flux:callout.text>{{ __('You can view this store but not change it.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="code" :label="__('Code')" :description="__('Letters, digits, dashes and underscores. Used to sign in.')" type="text" required maxlength="20" :disabled="! $canUpdate" />
            <flux:input wire:model="name" :label="__('Name')" type="text" required maxlength="200" :disabled="! $canUpdate" />
            <flux:input wire:model="timezone" :label="__('Time zone')" :description="__('For example Europe/Paris, Asia/Tokyo or UTC+9.')" type="text" :disabled="! $canUpdate" />
            <flux:select wire:model="locale" :label="__('Language')" :disabled="! $canUpdate">
                <flux:select.option value="">—</flux:select.option>
                @foreach ($locales as $localeCode => $localeName)
                    <flux:select.option :value="$localeCode">{{ $localeName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="currency" :label="__('Currency')" :disabled="! $canUpdate">
                @foreach (['EUR', 'USD', 'JPY', 'GBP', 'CHF', 'KRW'] as $currencyCode)
                    <flux:select.option :value="$currencyCode">{{ $currencyCode }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="currency_symbol_style" :label="__('Currency symbol')" :disabled="! $canUpdate">
                <flux:select.option value="kanji">{{ __('Local (円, ₩…)') }}</flux:select.option>
                <flux:select.option value="international">{{ __('International (JPY, KRW…)') }}</flux:select.option>
            </flux:select>
        </div>
        @foreach (['code', 'name', 'timezone', 'locale', 'currency', 'currency_symbol_style'] as $field)
            @error($field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        @endforeach

        <flux:heading size="lg">{{ __('Contact') }}</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="email" :label="__('Email')" type="email" :disabled="! $canUpdate" />
            <flux:input wire:model="phone" :label="__('Phone')" type="tel" :disabled="! $canUpdate" />
            <flux:input wire:model="address_street" :label="__('Street')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="address_city" :label="__('City')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="address_postal" :label="__('Postal code')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="address_country" :label="__('Country')" type="text" :disabled="! $canUpdate" />
        </div>
        @foreach (['email', 'phone', 'address_street', 'address_city', 'address_postal', 'address_country'] as $field)
            @error($field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        @endforeach

        <flux:heading size="lg">{{ __('Scheduling') }}</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="week_start_day" :label="__('First day of the week')" :disabled="! $canUpdate">
                @foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $day => $dayName)
                    <flux:select.option :value="$day">{{ __($dayName) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="min_staff_per_day" :label="__('Minimum staff per day')" type="number" min="0" :disabled="! $canUpdate" />
            <flux:input wire:model="low_staff_hour_start" :label="__('Low staffing starts at (hour)')" type="number" min="0" max="23" :disabled="! $canUpdate" />
            <flux:input wire:model="low_staff_hour_end" :label="__('Low staffing ends at (hour)')" type="number" min="0" max="23" :disabled="! $canUpdate" />
            <flux:input wire:model="min_shift_minutes" :label="__('Minimum shift length (minutes)')" type="number" min="0" max="1440" :disabled="! $canUpdate" />
            <flux:input wire:model="max_shift_minutes" :label="__('Maximum shift length (minutes)')" type="number" min="0" max="1440" :disabled="! $canUpdate" />
        </div>
        @foreach (['week_start_day', 'min_staff_per_day', 'low_staff_hour_start', 'low_staff_hour_end', 'min_shift_minutes', 'max_shift_minutes'] as $field)
            @error($field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        @endforeach

        <flux:checkbox wire:model="is_active" :label="__('Active store')" :disabled="! $canUpdate" />
        @error('is_active') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

        @if ($featureLabels !== [])
            <flux:heading size="lg">{{ __('Features') }}</flux:heading>
            <div class="space-y-2" data-test="features-section">
                @foreach ($featureLabels as $key => $label)
                    <flux:checkbox wire:model="features.{{ $key }}" :label="__($label)" :disabled="! $canUpdate" wire:key="feature-{{ $key }}" />
                @endforeach
            </div>
        @endif

        @if ($canUpdate)
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" data-test="save-store-button">{{ __('Save') }}</flux:button>
            </div>
        @endif
    </form>

    @if ($canManageMembers)
        <div class="space-y-4" data-test="members-section">
            <flux:heading size="lg">{{ __('Members') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Employee code') }}</flux:table.column>
                    <flux:table.column>{{ __('Staff code') }}</flux:table.column>
                    <flux:table.column>{{ __('Hired on') }}</flux:table.column>
                    <flux:table.column>{{ __('Contract type') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($members as $user)
                        <flux:table.row :key="$user->id" data-test="member-row">
                            <flux:table.cell>{{ $user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $user->employee_code }}</flux:table.cell>
                            <flux:table.cell>{{ $user->pivot->staff_code }}</flux:table.cell>
                            <flux:table.cell>{{ $user->pivot->hire_date?->format('Y-m-d') }}</flux:table.cell>
                            <flux:table.cell>{{ $user->pivot->contract_type }}</flux:table.cell>
                            <flux:table.cell>{{ $roleNames[$user->id] ?? '' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$user->pivot->is_active ? 'green' : 'zinc'" inset="top bottom">
                                    {{ $user->pivot->is_active ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" wire:click="editMember({{ $user->id }})" data-test="edit-member-button">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="confirmRemoval({{ $user->id }})" data-test="remove-member-button">{{ __('Remove') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8">{{ __('This store has no member yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <form wire:submit="addMember" class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="add-member-form">
                <flux:heading>{{ __('Add a member') }}</flux:heading>

                <flux:input wire:model.live.debounce.300ms="candidateSearch" icon="magnifying-glass" :placeholder="__('Search by name, email or employee code')" clearable />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="add.user_id" :label="__('Employee')">
                        <flux:select.option value="">{{ $candidates->isEmpty() ? __('Search to list employees') : __('Choose…') }}</flux:select.option>
                        @foreach ($candidates as $candidate)
                            <flux:select.option :value="$candidate->id">{{ $candidate->name }}@if ($candidate->employee_code) ({{ $candidate->employee_code }})@endif</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="add.role_id" :label="__('Role')">
                        @foreach ($memberRoles as $role)
                            <flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="add.staff_code" :label="__('Staff code')" type="text" maxlength="50" />
                    <flux:input wire:model="add.hire_date" :label="__('Hired on')" type="date" />
                    <flux:input wire:model="add.contract_type" :label="__('Contract type')" type="text" maxlength="50" />
                </div>
                @foreach (['user_id', 'role_id', 'staff_code', 'hire_date', 'contract_type'] as $field)
                    @error('add.'.$field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                @endforeach

                <flux:button type="submit" data-test="add-member-button">{{ __('Add') }}</flux:button>
            </form>

            <flux:modal name="edit-member" class="max-w-lg">
                <form wire:submit="saveMember" class="space-y-4">
                    <flux:heading size="lg">{{ __('Edit member') }}</flux:heading>

                    <flux:input wire:model="member.staff_code" :label="__('Staff code')" type="text" maxlength="50" />
                    <flux:input wire:model="member.hire_date" :label="__('Hired on')" type="date" />
                    <flux:input wire:model="member.contract_type" :label="__('Contract type')" type="text" maxlength="50" />
                    <flux:select wire:model="member.role_id" :label="__('Role')">
                        <flux:select.option value="">{{ __('Unchanged') }}</flux:select.option>
                        @foreach ($memberRoles as $role)
                            <flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:checkbox wire:model="member.is_active" :label="__('Active in this store')" />

                    @foreach (['staff_code', 'hire_date', 'contract_type', 'role_id', 'is_active'] as $field)
                        @error('member.'.$field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                    @endforeach

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="save-member-button">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="confirm-member-removal" class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Remove this member?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('The employee leaves this store and loses the roles held in it. Existing shifts are kept.') }}
                        </flux:subheading>
                    </div>

                    @error('removal.member') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="removeMember" data-test="confirm-remove-member-button">{{ __('Remove') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endif

    @if ($canDelete)
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete store') }}</flux:heading>
            <flux:modal.trigger name="confirm-store-deletion">
                <flux:button variant="danger" data-test="delete-store-button">{{ __('Delete store') }}</flux:button>
            </flux:modal.trigger>

            <flux:modal name="confirm-store-deletion" focusable class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete this store?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('The store disappears from the application and its code becomes available again. Its shifts are kept.') }}
                        </flux:subheading>
                    </div>

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-store-button">{{ __('Delete store') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endif
</section>
