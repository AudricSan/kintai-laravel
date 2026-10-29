<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

final class UserFormViewTest extends TestCase
{
    /**
     * Régression : le mini-formulaire "Réinitialiser le mot de passe" était un
     * <form> imbriqué dans le <form> d'édition de users-form.php. HTML interdit
     * les formulaires imbriqués — le navigateur referme le <form> englobant dès
     * la balise fermante du <form> interne, éjectant tout ce qui suit (dont le
     * bouton "Enregistrer") hors de tout formulaire et le rendant inopérant
     * (button.form === null). Le bouton doit désormais cibler par attribut
     * form="resetPasswordForm" un <form> autonome placé par users-form.php après
     * la fermeture du <form> d'édition, sans jamais imbriquer de <form> ici.
     */
    public function testEditModeDoesNotNestAFormInsideTheEditForm(): void
    {
        $view = kintai_test_views(dirname(__DIR__, 2) . '/resources/views');

        $html = $view->make('_partials._form-user', [
            'as_cards' => true,
            'mode'     => 'edit',
            'user'     => ['id' => 75],
            'BASE_URL' => '',
        ])->render();

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('form="resetPasswordForm"', $html);
    }
}
