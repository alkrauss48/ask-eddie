<?php

use App\Http\Controllers\BartendersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Bar's Web Door
|--------------------------------------------------------------------------
|
| Everything in this file sits inside one bar.key group, and it should stay
| that way. Putting the middleware on each route individually would mean a
| route added in a hurry is public until somebody notices, and "somebody
| notices" is not a security control for an endpoint that spends money at an
| AI provider. The group is the default; an exception would have to be written
| outside it, deliberately and visibly.
|
| VerifyBarKey refuses everything when no key is configured, so an empty
| BAR_API_KEYS closes this file rather than opening it.
|
*/

Route::middleware('bar.key')->group(function (): void {
    Route::get('/bartenders', BartendersController::class)->name('api.bartenders');
});
