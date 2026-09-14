<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\MobileServiceProvider;

return [
    AppServiceProvider::class,
    MobileServiceProvider::class,
    AdminPanelProvider::class,
];
