<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BatteryController;
use App\Http\Controllers\Api\SolarSystemController;
use App\Http\Controllers\Api\PhaseBalanceController;
use App\Http\Controllers\Api\ProjectBackupController;
use App\Http\Controllers\Api\ServerBackupController;
use App\Http\Controllers\Api\BuildingComponentController;
use App\Http\Controllers\Api\LoadProfileController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\TotalPowerController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\ComponentTypeController;
use App\Http\Controllers\Api\FloorComponentController;
use App\Http\Controllers\Api\FloorController;
use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\ProjectComponentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\RoomComponentController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GeneratorLineController;
use App\Http\Controllers\Api\SocketController;
use App\Http\Controllers\Api\UtilityLineController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api-general'])->group(function () {
    Route::get('/user', [UserController::class, 'show']);
    Route::post('/logout', [UserController::class, 'logout']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

    // Project-wide listing
    Route::get('/projects/{project}/all-floors', [ProjectController::class, 'allFloors']);
    Route::get('/projects/{project}/all-rooms',  [ProjectController::class, 'allRooms']);

    // Project backup / restore
    Route::get('/projects/{project}/backup', [ProjectBackupController::class, 'backup'])->middleware('throttle:api-heavy');
    Route::post('/projects/restore',         [ProjectBackupController::class, 'restore']);

    // Building backup / restore
    Route::get('/buildings/{building}/backup',          [ProjectBackupController::class, 'backupBuilding']);
    Route::post('/projects/{project}/buildings/restore', [ProjectBackupController::class, 'restoreBuilding']);

    // Floor backup / restore
    Route::get('/floors/{floor}/backup',                [ProjectBackupController::class, 'backupFloor']);
    Route::post('/buildings/{building}/floors/restore', [ProjectBackupController::class, 'restoreFloor']);

    // Room backup / restore
    Route::get('/rooms/{room}/backup',               [ProjectBackupController::class, 'backupRoom']);
    Route::post('/floors/{floor}/rooms/restore',     [ProjectBackupController::class, 'restoreRoom']);

    // Save backup to server
    Route::post('/projects/{project}/save-backup',   [ProjectBackupController::class, 'saveProjectToServer'])->middleware('throttle:api-heavy');
    Route::post('/buildings/{building}/save-backup', [ProjectBackupController::class, 'saveBuildingToServer']);
    Route::post('/floors/{floor}/save-backup',       [ProjectBackupController::class, 'saveFloorToServer']);
    Route::post('/rooms/{room}/save-backup',         [ProjectBackupController::class, 'saveRoomToServer']);

    // Server backup management
    Route::get('/projects/{project}/server-backups',  [ServerBackupController::class, 'index']);
    Route::get('/server-backups/{backup}/data',       [ServerBackupController::class, 'show']);
    Route::delete('/server-backups/{backup}',         [ServerBackupController::class, 'destroy']);

    // Duplicate
    Route::post('/projects/{project}/buildings/{building}/duplicate', [ProjectBackupController::class, 'duplicateBuilding']);
    Route::post('/buildings/{building}/floors/{floor}/duplicate',     [ProjectBackupController::class, 'duplicateFloor']);
    Route::post('/floors/{floor}/rooms/{room}/duplicate',             [ProjectBackupController::class, 'duplicateRoom']);

    // Project member management (admin only)
    Route::get('/projects/{project}/members', [ProjectMemberController::class, 'index']);
    Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store']);
    Route::put('/projects/{project}/members/{member}', [ProjectMemberController::class, 'update']);
    Route::delete('/projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy']);

    Route::get('/projects/{project}/buildings', [BuildingController::class, 'index']);
    Route::post('/projects/{project}/buildings', [BuildingController::class, 'store']);
    Route::put('/projects/{project}/buildings/{building}', [BuildingController::class, 'update']);
    Route::delete('/projects/{project}/buildings/{building}', [BuildingController::class, 'destroy']);

    Route::get('/buildings/{building}/floors', [FloorController::class, 'index']);
    Route::post('/buildings/{building}/floors', [FloorController::class, 'store']);
    Route::put('/buildings/{building}/floors/{floor}', [FloorController::class, 'update']);
    Route::delete('/buildings/{building}/floors/{floor}', [FloorController::class, 'destroy']);

    Route::get('/floors/{floor}/rooms', [RoomController::class, 'index']);
    Route::post('/floors/{floor}/rooms', [RoomController::class, 'store']);
    Route::put('/floors/{floor}/rooms/{room}', [RoomController::class, 'update']);
    Route::delete('/floors/{floor}/rooms/{room}', [RoomController::class, 'destroy']);

    Route::get('/component-types', [ComponentTypeController::class, 'index']);

    Route::get('/projects/{project}/phase-balance',  [PhaseBalanceController::class, 'project'])->middleware('throttle:api-heavy');
    Route::get('/buildings/{building}/phase-balance', [PhaseBalanceController::class, 'building'])->middleware('throttle:api-heavy');
    Route::get('/floors/{floor}/phase-balance',      [PhaseBalanceController::class, 'floor'])->middleware('throttle:api-heavy');
    Route::post('/rooms/{room}/assign-phase',                  [PhaseBalanceController::class, 'assignRoom']);
    Route::post('/buildings/{building}/apply-optimal-phase',   [PhaseBalanceController::class, 'applyOptimalBuilding']);

    Route::get('/projects/{project}/load-profile',  [LoadProfileController::class, 'project'])->middleware('throttle:api-heavy');
    Route::get('/projects/{project}/schedule',      [ScheduleController::class, 'project']);
    Route::get('/projects/{project}/total-power',  [TotalPowerController::class, 'project'])->middleware('throttle:api-heavy');
    Route::get('/buildings/{building}/total-power', [TotalPowerController::class, 'building'])->middleware('throttle:api-heavy');
    Route::get('/floors/{floor}/total-power',       [TotalPowerController::class, 'floor'])->middleware('throttle:api-heavy');
    Route::get('/rooms/{room}/total-power',         [TotalPowerController::class, 'room'])->middleware('throttle:api-heavy');

    Route::get('/projects/{project}/components', [ProjectComponentController::class, 'index']);
    Route::post('/projects/{project}/components', [ProjectComponentController::class, 'store']);
    Route::put('/projects/{project}/components/{component}', [ProjectComponentController::class, 'update']);
    Route::delete('/projects/{project}/components/{component}', [ProjectComponentController::class, 'destroy']);

    Route::get('/buildings/{building}/components', [BuildingComponentController::class, 'index']);
    Route::post('/buildings/{building}/components', [BuildingComponentController::class, 'store']);
    Route::put('/buildings/{building}/components/{component}', [BuildingComponentController::class, 'update']);
    Route::delete('/buildings/{building}/components/{component}', [BuildingComponentController::class, 'destroy']);

    Route::get('/floors/{floor}/components', [FloorComponentController::class, 'index']);
    Route::post('/floors/{floor}/components', [FloorComponentController::class, 'store']);
    Route::put('/floors/{floor}/components/{component}', [FloorComponentController::class, 'update']);
    Route::delete('/floors/{floor}/components/{component}', [FloorComponentController::class, 'destroy']);

    Route::get('/nav/{projectName}', [NavigationController::class, 'project']);
    Route::get('/nav/{projectName}/{buildingName}', [NavigationController::class, 'building']);
    Route::get('/nav/{projectName}/{buildingName}/{floorName}', [NavigationController::class, 'floor']);
    Route::get('/nav/{projectName}/{buildingName}/{floorName}/{roomName}', [NavigationController::class, 'room']);

    Route::get('/rooms/{room}/components', [RoomComponentController::class, 'index']);
    Route::post('/rooms/{room}/components', [RoomComponentController::class, 'store']);
    Route::put('/rooms/{room}/components/{component}', [RoomComponentController::class, 'update']);
    Route::delete('/rooms/{room}/components/{component}', [RoomComponentController::class, 'destroy']);

    // Utility lines (polymorphic — one controller, four parent types)
    Route::get('/projects/{id}/utility-lines',  function (Request $request, $id) { return app(UtilityLineController::class)->index($request, 'project', $id); });
    Route::post('/projects/{id}/utility-lines', function (Request $request, $id) { return app(UtilityLineController::class)->store($request, 'project', $id); });

    Route::get('/buildings/{id}/utility-lines',  function (Request $request, $id) { return app(UtilityLineController::class)->index($request, 'building', $id); });
    Route::post('/buildings/{id}/utility-lines', function (Request $request, $id) { return app(UtilityLineController::class)->store($request, 'building', $id); });

    Route::get('/floors/{id}/utility-lines',  function (Request $request, $id) { return app(UtilityLineController::class)->index($request, 'floor', $id); });
    Route::post('/floors/{id}/utility-lines', function (Request $request, $id) { return app(UtilityLineController::class)->store($request, 'floor', $id); });

    Route::get('/rooms/{id}/utility-lines',  function (Request $request, $id) { return app(UtilityLineController::class)->index($request, 'room', $id); });
    Route::post('/rooms/{id}/utility-lines', function (Request $request, $id) { return app(UtilityLineController::class)->store($request, 'room', $id); });

    Route::put('/utility-lines/{line}',    [UtilityLineController::class, 'update']);
    Route::delete('/utility-lines/{line}', [UtilityLineController::class, 'destroy']);

    // Generator lines (polymorphic)
    Route::get('/projects/{id}/generator-lines',  function (Request $request, $id) { return app(GeneratorLineController::class)->index($request, 'project', $id); });
    Route::post('/projects/{id}/generator-lines', function (Request $request, $id) { return app(GeneratorLineController::class)->store($request, 'project', $id); });

    Route::get('/buildings/{id}/generator-lines',  function (Request $request, $id) { return app(GeneratorLineController::class)->index($request, 'building', $id); });
    Route::post('/buildings/{id}/generator-lines', function (Request $request, $id) { return app(GeneratorLineController::class)->store($request, 'building', $id); });

    Route::get('/floors/{id}/generator-lines',  function (Request $request, $id) { return app(GeneratorLineController::class)->index($request, 'floor', $id); });
    Route::post('/floors/{id}/generator-lines', function (Request $request, $id) { return app(GeneratorLineController::class)->store($request, 'floor', $id); });

    Route::get('/rooms/{id}/generator-lines',  function (Request $request, $id) { return app(GeneratorLineController::class)->index($request, 'room', $id); });
    Route::post('/rooms/{id}/generator-lines', function (Request $request, $id) { return app(GeneratorLineController::class)->store($request, 'room', $id); });

    Route::put('/generator-lines/{line}',    [GeneratorLineController::class, 'update']);
    Route::delete('/generator-lines/{line}', [GeneratorLineController::class, 'destroy']);

    // Sockets (polymorphic)
    Route::get('/projects/{id}/sockets',   function (Request $request, $id) { return app(SocketController::class)->index($request, 'project', $id); });
    Route::post('/projects/{id}/sockets',  function (Request $request, $id) { return app(SocketController::class)->store($request, 'project', $id); });

    Route::get('/buildings/{id}/sockets',  function (Request $request, $id) { return app(SocketController::class)->index($request, 'building', $id); });
    Route::post('/buildings/{id}/sockets', function (Request $request, $id) { return app(SocketController::class)->store($request, 'building', $id); });

    Route::get('/floors/{id}/sockets',     function (Request $request, $id) { return app(SocketController::class)->index($request, 'floor', $id); });
    Route::post('/floors/{id}/sockets',    function (Request $request, $id) { return app(SocketController::class)->store($request, 'floor', $id); });

    Route::get('/rooms/{id}/sockets',      function (Request $request, $id) { return app(SocketController::class)->index($request, 'room', $id); });
    Route::post('/rooms/{id}/sockets',     function (Request $request, $id) { return app(SocketController::class)->store($request, 'room', $id); });

    Route::put('/sockets/{socket}',    [SocketController::class, 'update']);
    Route::delete('/sockets/{socket}', [SocketController::class, 'destroy']);

    // Named solar systems (project-scoped)
    Route::get(   '/projects/{project}/solar-systems',    [SolarSystemController::class, 'index']);
    Route::post(  '/projects/{project}/solar-systems',    [SolarSystemController::class, 'store']);
    Route::put(   '/solar-systems/{solarSystem}',         [SolarSystemController::class, 'update']);
    Route::delete('/solar-systems/{solarSystem}',         [SolarSystemController::class, 'destroy']);

    // Battery banks (project-scoped)
    Route::get(   '/projects/{project}/batteries',        [BatteryController::class, 'index']);
    Route::post(  '/projects/{project}/batteries',        [BatteryController::class, 'store']);
    Route::get(   '/projects/{project}/battery-runtime',  [BatteryController::class, 'projectRuntime']);
    Route::get(   '/batteries/{battery}',                 [BatteryController::class, 'show']);
    Route::put(   '/batteries/{battery}',                 [BatteryController::class, 'update']);
    Route::delete('/batteries/{battery}',                 [BatteryController::class, 'destroy']);
    Route::post(  '/batteries/{battery}/reset-soc',       [BatteryController::class, 'resetSoc']);
    Route::post(  '/batteries/{battery}/runtime-at-load', [BatteryController::class, 'runtimeAtLoad']);
});

// Public reference data
Route::get('/battery-chemistry-defaults', [BatteryController::class, 'chemistryDefaults']);

// Admin public route
Route::post('/admin/login', [AdminController::class, 'login']);

// Admin protected routes
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::put('/admin/users/{user}', [AdminController::class, 'updateUser']);
    Route::delete('/admin/users/{user}', [AdminController::class, 'deleteUser']);
});
