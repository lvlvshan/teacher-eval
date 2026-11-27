<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\View;

/**
 * 向所有模板注入 flash 提示、登录信息与当前菜单
 */
class ShareView
{
    public function handle($request, \Closure $next)
    {
        View::assign('flash', session('flash'));
        View::assign('adminName', session('admin_name'));
        View::assign('adminRole', session('admin_role'));
        session('flash', null);

        $path = trim((string) $request->pathinfo(), '/');
        $seg = explode('/', $path);
        $active = '';
        if (($seg[0] ?? '') === 'admin') {
            $s = $seg[1] ?? '';
            $map = [
                ''          => 'dashboard',
                'dashboard' => 'dashboard',
                'periods'   => 'periods',
                'teachers'  => 'teachers',
                'classes'   => 'classes',
                'courses'   => 'courses',
                'detail'    => 'detail',
                'votes'     => 'votes',
                'export'    => 'export',
            ];
            $active = $map[$s] ?? '';
        }
        View::assign('active', $active);

        return $next($request);
    }
}
