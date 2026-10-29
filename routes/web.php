<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Admin\AdminAttendanceController;
use App\Http\Controllers\CorrectionRequestController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// 未ログイン時のリダイレクト（トップページアクセス時）
Route::get('/', function () {
    return redirect('/login');
});

// ==========================================
// 一般ユーザー用ルート（認証必須）
// ==========================================
Route::middleware(['auth', 'verified'])->group(function () {

    // PG03: 勤怠登録画面（打刻画面）
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])->name('attendance.clockIn');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])->name('attendance.clockOut');
    Route::post('/attendance/rest-start', [AttendanceController::class, 'restStart'])->name('attendance.restStart');
    Route::post('/attendance/rest-end', [AttendanceController::class, 'restEnd'])->name('attendance.restEnd');

    // PG04: 勤怠一覧画面
    Route::get('/attendance/list', [AttendanceController::class, 'list'])->name('attendance.list');

    // PG14: マイ勤怠レポート
    Route::get('/attendance/report', [ReportController::class, 'index'])->name('attendance.report');

    // PG05: 勤怠詳細画面・修正申請
    Route::get('/attendance/detail/{id}', [AttendanceController::class, 'detail'])->name('attendance.detail');
    Route::post('/attendance/detail/{id}', [AttendanceController::class, 'requestCorrection'])->name('attendance.requestCorrection');

    // PG06 / PG12: 申請一覧画面（一般・管理者共通パス）
    Route::get('/stamp_correction_request/list', [CorrectionRequestController::class, 'list'])->name('request.list');
});

// ==========================================
// 管理者専用ルート（認証必須かつ role = 2）
// ==========================================
Route::middleware(['auth', 'admin'])->group(function () {

    // PG13: 修正申請承認画面・承認処理
    Route::get('/stamp_correction_request/approve/{attendance_correct_request_id}', [CorrectionRequestController::class, 'showApproveForm'])->name('request.approve.form');
    Route::post('/stamp_correction_request/approve/{attendance_correct_request_id}', [CorrectionRequestController::class, 'approve'])->name('request.approve');

    // FN045: スタッフ別勤怠の CSV 出力
    Route::post('/export', [AdminAttendanceController::class, 'export'])->name('admin.attendance.export');
});

// ==========================================
// 管理者用ルート（認証必須かつ role = 2）
// ==========================================
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {

    // PG08: 勤怠一覧画面（管理者）
    Route::get('/attendance/list', [AdminAttendanceController::class, 'list'])->name('admin.attendance.list');

    // PG09: 勤怠詳細画面（管理者による直接修正）
    Route::get('/attendance/{id}', [AdminAttendanceController::class, 'detail'])->name('admin.attendance.detail');
    Route::post('/attendance/{id}', [AdminAttendanceController::class, 'update'])->name('admin.attendance.update');

    // PG10: スタッフ一覧画面
    Route::get('/staff/list', [AdminAttendanceController::class, 'staffList'])->name('admin.staff.list');

    // PG11: スタッフ別勤怠一覧画面
    Route::get('/attendance/staff/{id}', [AdminAttendanceController::class, 'staffAttendance'])->name('admin.staff.attendance');
});