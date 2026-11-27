<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Db;
use think\Request;
use think\Response;

/**
 * 后台登录校验
 */
class AdminAuth
{
    public function handle(Request $request, \Closure $next)
    {
        $adminId = session('admin_id');
        if (!$adminId) {
            if ($request->isAjax() || $request->isPost()) {
                return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
            }
            return redirect('/admin/login');
        }
        $admin = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            session('admin_id', null);
            return redirect('/admin/login');
        }
        app()->bind('currentAdmin', $admin);
        return $next($request);
    }
}
