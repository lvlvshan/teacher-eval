<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;

class Auth extends BaseController
{
    public function login()
    {
        if (session('admin_id')) {
            return redirect('/admin');
        }
        return view('admin/login');
    }

    public function doLogin()
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        if ($username === '' || $password === '') {
            return $this->fail('请输入账号和密码');
        }

        $admin = Db::name('admin')->where('username', $username)->find();
        if (!$admin || !password_verify($password, $admin['password'])) {
            return $this->fail('账号或密码错误');
        }

        session('admin_id', (int) $admin['id']);
        session('admin_name', $admin['username']);
        session('admin_role', $admin['role']);

        Db::name('admin')->where('id', $admin['id'])->update(['last_login_at' => date('Y-m-d H:i:s')]);
        Db::name('audit_log')->insert([
            'admin_id'    => $admin['id'],
            'action'      => 'login',
            'target'      => 'admin#' . $admin['id'],
            'detail'      => '',
            'ip_hash'     => ev_hash(ev_client_ip()),
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        return redirect('/admin');
    }

    public function logout()
    {
        session(null);
        return redirect('/admin/login');
    }

    protected function fail($msg)
    {
        session('flash', ['type' => 'err', 'msg' => $msg]);
        return redirect('/admin/login');
    }
}
