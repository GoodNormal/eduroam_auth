<?php

use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

return function (Dispatcher $events, Filter $filter) {
    Hook::addRoute(function () {
        Route::namespace('AuthEduroam')
            ->prefix('auth')
            ->name('auth.')
            ->middleware(['web','guest'])
            ->group(function () {
                Route::get('/eduroam/login', 'LoginController@showLoginForm')
                    ->name('auth.eduroam.login');
                Route::post('/eduroam/login', 'LoginController@handleLogin')
                    ->name('auth.eduroam.login.post');
            });
    });

    View::composer('AuthEduroam::providers', function ($view) use ($filter) {
        $providers = $filter->apply('eduroam_providers', collect());
        $view->with('providers', $providers);
    });

    $filter->add('auth_page_rows:login', function ($rows) {
        $length = count($rows);
        array_splice($rows, $length - 1, 0, ['AuthEduroam::providers']);
        return $rows;
    });

    $filter->add('auth_page_rows:register', function ($rows) {
        $rows[] = 'AuthEduroam::providers';
        return $rows;
    });

    $filter->add('eduroam_providers', function (Collection $providers) {
        $providers->put('eduroam/login', [
            'icon' => 'wifi fas',
            'displayName' => trans('AuthEduroam::auth.eduroam.login', ['eduroam_name' => trans('AuthEduroam::auth.eduroam.name')]),
        ]);
        return $providers;
    });
};
