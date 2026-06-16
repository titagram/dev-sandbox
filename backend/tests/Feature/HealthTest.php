<?php

use Illuminate\Support\Facades\Route;

it('loads the application test environment', function () {
    Route::get('/_test/health', fn () => response()->json(['ok' => true]));

    $this->getJson('/_test/health')
        ->assertOk()
        ->assertJson(['ok' => true]);
});
