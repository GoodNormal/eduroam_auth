<?php

use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

return function (Dispatcher $events, Filter $filter) {
    Hook::addRoute(function () {
        $routes = Route::getRoutes()->getRoutes();
        $routes = array_filter($routes, function ($route) {
            return Str::startsWith($route->uri(), 'user');
        });
        array_walk($routes, function ($route) {
            $route->middleware(RequirePassword\RequirePassword::class);
        });
    });
};
