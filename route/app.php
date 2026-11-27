<?php
use think\facade\Route;

// ---------------- 学生端（免登录，按 IP 自动识别班级） ----------------
Route::get('/', 'evaluate/home');
Route::get('c/:cid/t/:target', 'evaluate/form');
Route::post('c/:cid/t/:target', 'evaluate/submit');
Route::get('c/:cid/done/:target', 'evaluate/done');
Route::get('c/:cid', 'evaluate/index');
Route::get('closed', 'evaluate/closed');

// ---------------- 后台登录（不鉴权） ----------------
Route::get('admin/login', 'admin.auth/login');
Route::post('admin/login', 'admin.auth/doLogin');
Route::get('admin/logout', 'admin.auth/logout');

// ---------------- 后台（需登录） ----------------
Route::group('admin', function () {
    Route::get('/', 'admin.report/dashboard');
    Route::get('dashboard', 'admin.report/dashboard');

    // 基础数据
    Route::get('teachers', 'admin.setup/teachers');
    Route::post('teachers/save', 'admin.setup/saveTeacher');
    Route::post('teachers/delete', 'admin.setup/deleteTeacher');
    Route::post('teachers/import', 'admin.setup/importTeachers');

    Route::get('classes', 'admin.setup/classes');
    Route::post('classes/save', 'admin.setup/saveClass');
    Route::post('classes/delete', 'admin.setup/deleteClass');

    Route::get('courses', 'admin.setup/courses');
    Route::post('courses/save', 'admin.setup/saveCourse');
    Route::post('courses/delete', 'admin.setup/deleteCourse');

    // 周期 / 场次 / 测评对象 / 归档
    Route::get('periods', 'admin.period/index');
    Route::post('periods/save', 'admin.period/save');
    Route::post('periods/state', 'admin.period/changeState');
    Route::get('periods/:id/targets', 'admin.period/targets');
    Route::post('periods/:id/targets/save', 'admin.period/saveTargets');
    Route::post('periods/:id/ip', 'admin.period/saveIp');
    Route::get('periods/:id/slots', 'admin.period/slots');
    Route::post('periods/:id/slots/save', 'admin.period/saveSlot');
    Route::post('periods/:id/slots/delete', 'admin.period/deleteSlot');
    Route::post('periods/:id/archive', 'admin.period/archive');
    Route::post('periods/:id/unarchive', 'admin.period/unarchive');

    // 问卷
    Route::get('periods/:id/questionnaire', 'admin.questionnaire/index');
    Route::post('periods/:id/questionnaire', 'admin.questionnaire/save');

    // 明细 / 投票详情 / 导出
    Route::get('detail', 'admin.report/detail');
    Route::post('detail/review', 'admin.report/review');
    Route::get('votes', 'admin.report/votes');
    Route::get('votes/:id', 'admin.report/voteDetail');
    Route::get('export', 'admin.report/exportPage');
    Route::post('export/annual', 'admin.report/exportAnnual');
    Route::post('export/pack', 'admin.report/exportPack');
})->middleware(\app\middleware\AdminAuth::class);
