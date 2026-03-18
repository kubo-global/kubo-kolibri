<?php

use Illuminate\Support\Facades\Route;
use KuboKolibri\Http\Controllers\ContentController;

Route::prefix('kolibri')->middleware(['web', 'auth'])->group(function () {

    // Student-facing: view content for a topic
    Route::get('content/{subjectId}/{topicId}', [ContentController::class, 'forTopic'])
        ->name('kolibri.topic-content');

    // Redirect to Kolibri content (auto-login + redirect)
    Route::get('go/{nodeId}', [ContentController::class, 'redirect'])
        ->name('kolibri.redirect');

    // Server-side progress check (avoids CORS)
    Route::get('progress/{nodeId}', [ContentController::class, 'checkProgress'])
        ->name('kolibri.check-progress');

    // Adaptive engine: next exercise for student
    Route::get('next/{subjectId}/{topicId}', [ContentController::class, 'nextExercise'])
        ->name('kolibri.next-exercise');

    // Curriculum mapping API (teachers/admins)
    Route::get('channels', [ContentController::class, 'channels'])
        ->name('kolibri.channels');
    Route::get('browse/{nodeId}', [ContentController::class, 'browseContent'])
        ->name('kolibri.browse');
    Route::get('search', [ContentController::class, 'searchContent'])
        ->name('kolibri.search');
    Route::post('mapping', [ContentController::class, 'createMapping'])
        ->name('kolibri.create-mapping');
    Route::delete('mapping/{mapId}', [ContentController::class, 'deleteMapping'])
        ->name('kolibri.delete-mapping');
});
