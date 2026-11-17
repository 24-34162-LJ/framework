<?php

/*
 * FILE PURPOSE
 * File   : bootstrap/app.php
 * Feature: Core
 * Purpose: Laravel start-up file: loads the routes and registers middleware and exception handling.
 */

use App\Http\Middleware\LegacyCsrfToken;
use App\Http\Middleware\LegacyRoleMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', LegacyCsrfToken::class);
        $middleware->encryptCookies(except: ['ASTRASESSID']);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'legacy.role' => LegacyRoleMiddleware::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The Add Shipment page (shipment_add.js) parses every AJAX answer as JSON and shows `error`,
        // like the prototype's jsonOut(). Never answer those calls with an HTML page or redirect.
        $exceptions->render(function (Throwable $e, Request $request) {
            $isAddShipmentAjax = ($request->is('shipment-management/shipment_add.php') && $request->isMethod('post'))
                || $request->is('shipments/add', 'shipments/add/*');
            if (! $isAddShipmentAjax) {
                return null;
            }
            [$status, $message] = match (true) {
                $e instanceof Illuminate\Validation\ValidationException => [422, $e->validator->errors()->first()],
                $e instanceof Illuminate\Http\Exceptions\PostTooLargeException => [413, 'Upload failed: the file is larger than the server allows. Raise upload_max_filesize and post_max_size in php.ini (currently '.ini_get('upload_max_filesize').' / '.ini_get('post_max_size').').'],
                $e instanceof Illuminate\Session\TokenMismatchException => [403, 'Your session expired. Reload the page and try again.'],
                $e instanceof Illuminate\Auth\AuthenticationException => [401, 'Your session expired. Please log in again.'],
                $e instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'The request could not be completed.'],
                default => [500, 'Something went wrong on the server. No shipment has been saved.'],
            };

            return response()->json(['error' => $message], $status);
        });
    })->create();
