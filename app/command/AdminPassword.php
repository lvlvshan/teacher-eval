<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

/**
 * 重置后台管理员密码
 * 用法：php think admin:password admin 新密码
 */
class AdminPassword extends Command
{
    protected function configure()
    {
        $this->setName('admin:password')
            ->addArgument('username', Argument::REQUIRED, '管理员账号')
            ->addArgument('password', Argument::OPTIONAL, '新密码，留空则随机生成')
            ->setDescription('重置后台管理员密码');
    }

    protected function execute(Input $input, Output $output)
    {
        $username = trim((string) $input->getArgument('username'));
        $password = (string) $input->getArgument('password');

        if ($password === '') {
            $password = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10);
        }
        if (strlen($password) < 6) {
            $output->writeln('<error>密码至少 6 位</error>');
            return 1;
        }

        $admin = Db::name('admin')->where('username', $username)->find();
        if (!$admin) {
            $output->writeln('<error>管理员不存在：' . $username . '</error>');
            return 1;
        }

        Db::name('admin')->where('id', $admin['id'])->update([
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        Db::name('audit_log')->insert([
            'admin_id'    => 0,
            'action'      => 'admin_password_reset',
            'target'      => 'admin#' . $admin['id'],
            'detail'      => '',
            'ip_hash'     => '',
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        $output->writeln('已重置账号 ' . $username . ' 的密码：' . $password);
        return 0;
    }
}
