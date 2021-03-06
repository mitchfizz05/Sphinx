<?php

use Illuminate\Support\Facades\Route;

/*
 * Sphinx Routes
*/

Route::get('/', function () {
    return redirect('/sphinx/dashboard');
});

if (env('APP_DEBUG') && !App\Facades\MinecraftAuth::check()) {
    App\Facades\MinecraftAuth::set(new App\Realms\Player('b6284cef69f440d2873054053b1a925d', 'mitchfizz05'));
}

Route::group(['middleware' => 'realms'], function () {
    // Availability.
    Route::get('/mco/available', 'AvailabilityController@available');
    Route::get('/mco/client/compatible', 'AvailabilityController@compatible');
    Route::get('/mco/stageAvailable', 'AvailabilityController@stagingAvailable');
    Route::post('/regions/ping/stat', 'AvailabilityController@regionPing');
    
	
	
	// Trials
	Route::get('/trial', 'AvailabilityController@trialAvailable');
	Route::post('/trial', 'RealmController@MakeTrialWorld');

    // Invites.
    Route::get('/invites/count/pending', 'InviteController@pendingCount');
    Route::get('/invites/pending', 'InviteController@view');
    Route::put('/invites/accept/{id}', 'InviteController@accept');
    Route::put('/invites/reject/{id}', 'InviteController@reject');
    Route::post('/invites/{id}', 'InviteController@invite');
    Route::delete('/invites/{id}/invite/{player}', 'RealmController@kick');

    // World temlates.
    Route::get('/worlds/templates', 'TemplateController@listing');

    // Realms
    Route::get('/worlds', 'RealmController@listing');
    Route::get('/activities/liveplayerlist', 'LiveActivityController@playerlist');
    Route::delete('/invites/{id}', 'RealmController@leave');

    // Realm Management
    Route::get('/worlds/{id}/join', 'RealmController@join');
    Route::put('/worlds/{id}/close', 'RealmController@close');
    Route::put('/worlds/{id}/open', 'RealmController@open');
    Route::get('/worlds/{id}', 'RealmController@view');
    Route::post('/worlds/{id}', 'RealmController@UpdateServerInfo');
    Route::post('/worlds/{id}/initialize', 'RealmController@InitServer');
    Route::post('/ops/{id}/{player}', 'OpController@op');
    Route::delete('/ops/{id}/{player}', 'OpController@deop');
    Route::get('/subscriptions/{id}', 'SubscriptionController@view');

    // World management
    Route::post('/worlds/{serverid}/slot/{slotid}', 'RealmController@updateSlot');
});

// Sphinx API
Route::group(['namespace' => 'NodeApi', 'prefix' => '/sphinx/api'], function () {
    Route::get('/ping', 'PingController@ping');
    Route::get('/request-manifest', 'ManifestController@request');
});

// Sphinx Dashboard
Route::group(['namespace' => 'Dashboard', 'prefix' => '/sphinx/dashboard', 'middleware' => 'web'], function () {
    // Login routes.
    Route::get('/login', 'AuthController@loginForm');
    Route::post('/login', 'AuthController@login');
    Route::get('/logout', 'AuthController@logout')->middleware('csrf.get');

    // Restricted routes that require the user to be logged in.
    Route::group(['middleware' => 'auth'], function () {
        Route::get('/', 'DashboardController@dashboard');
        Route::get('/realms', 'RealmsController@listing');
        Route::get('/users', 'UsersController@listing');

        Route::get('/ajax/stats', 'DashboardController@statsApi');
        Route::post('/ajax/create_realm', 'RealmsController@create');
        Route::post('/ajax/delete_realm', 'RealmsController@remove');
        Route::post('/ajax/create_user', 'UsersController@create');
        Route::post('/ajax/delete_user', 'UsersController@remove');
    });
});
