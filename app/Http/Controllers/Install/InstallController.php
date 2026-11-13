<?php

namespace App\Http\Controllers\Install;

use App\Install\Installer;
use App\Install\InstallFailure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Assistant d'installation web (hébergement sans terminal) : mêmes champs que `php artisan kintai:install`.
 * Le verrou et le 404 après installation sont assurés par le middleware `EnsureNotInstalled` des routes.
 */
class InstallController
{
    public function show(Request $request, Installer $installer): View
    {
        $installer->ensureSetupToken();

        return view('install.index', [
            'checks' => $installer->requirements()->checks(),
            'canInstall' => $installer->requirements()->passes(),
            'drivers' => $installer->requirements()->availableDrivers(),
            'databaseConfigured' => $installer->databaseConfigured(),
            'tokenFile' => $installer->setupTokenPath(),
            'defaultUrl' => $request->getSchemeAndHttpHost().$request->getBasePath(),
        ]);
    }

    public function store(Request $request, Installer $installer): RedirectResponse
    {
        $token = $request->input('setup_token');

        if (! is_string($token) || ! $installer->verifySetupToken($token)) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'db_password', 'setup_token']))
                ->withErrors(['install' => __('The setup token is missing or wrong.')]);
        }

        $input = $request->only([
            'name', 'email', 'password', 'password_confirmation',
            'db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'url',
        ]);

        try {
            $installer->install($input);
        } catch (InstallFailure $failure) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'db_password']))
                ->withErrors(['install' => $failure->getMessage()]);
        }

        return redirect()->route('login')->with('status', __('Kintai is installed. Sign in with the Owner account.'));
    }
}
