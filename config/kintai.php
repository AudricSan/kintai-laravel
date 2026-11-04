<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fuseau de l'instance
    |--------------------------------------------------------------------------
    |
    | Les horodatages sont stockés en UTC (app.timezone). Ce fuseau sert à saisir
    | et à afficher les heures des shifts.
    |
    */

    'timezone' => env('KINTAI_TIMEZONE', 'UTC'),

];
