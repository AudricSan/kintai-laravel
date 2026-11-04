<?php

use App\Actions\AnonymizeUser;
use App\Authorization\OwnerGuard;
use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Anonymize the currently authenticated user's account (history is kept).
     */
    public function deleteUser(Logout $logout, AnonymizeUser $anonymizeUser, OwnerGuard $ownerGuard): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        try {
            $ownerGuard->assertCanRemoveOwner($user, $user);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        $logout();
        $anonymizeUser->handle($user);

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('Your account will be anonymized: your personal data is erased and you can no longer sign in. Your shift history is kept, without your name. Please enter your password to confirm.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
