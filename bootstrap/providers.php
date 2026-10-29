<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ProspectorPrivateHttpsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    ProspectorPrivateHttpsServiceProvider::class, // EC_A242_TRUSTED_PRIVATE_PROXY
];
