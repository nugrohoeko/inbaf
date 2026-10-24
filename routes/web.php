<?php

use Illuminate\Support\Facades\Route;

Route::get('/api/admin/reports', [\App\Http\Controllers\ReportController::class, 'export'])->middleware(['auth', 'superadmin', 'throttle:10,1']);

foreach (['assessment', 'admin', 'certificates'] as $routes) {
    if (file_exists(__DIR__."/{$routes}.php")) require __DIR__."/{$routes}.php";
}

Route::get('/{path?}', function (\Illuminate\Http\Request $request) {
    $public = $request->is('/', 'login', 'register', 'set-password', 'verify', 'verify/*');
    if (! $public && ! $request->user()) return redirect('/login');
    if ($request->is('admin', 'admin/*')) abort_unless($request->user()?->isAdmin(), 403);
    return view('app');
})->where('path', '^(?!api(?:/|$)).*');
