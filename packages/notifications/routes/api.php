<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Notifications\Http\Controllers\DeviceController;
use Kern\Notifications\Http\Controllers\NoticeController;
use Kern\Notifications\Http\Controllers\PreferenceController;
use Kern\Notifications\Http\Controllers\SendTestPushController;

/*
|--------------------------------------------------------------------------
| Notifications — JSON routes
|--------------------------------------------------------------------------
|
| Loaded by `Kern\Notifications\NotificationsServiceProvider` under `notifications.routes`. Each acts
| for the signed-in person only. Changing devices is throttled, and a test send more so, since each is
| a request to a push service on the person's behalf.
*/

Route::get('/', [NoticeController::class, 'index']);
Route::post('read', [NoticeController::class, 'markRead']);

Route::get('preferences', [PreferenceController::class, 'index']);
Route::patch('preferences', [PreferenceController::class, 'update']);

Route::get('push/devices', [DeviceController::class, 'index']);
Route::post('push/devices', [DeviceController::class, 'store'])->middleware('throttle:notifications-devices');
Route::delete('push/devices/{deviceId}', [DeviceController::class, 'destroy'])->middleware('throttle:notifications-devices');
Route::post('push/test', SendTestPushController::class)->middleware('throttle:notifications-test');
