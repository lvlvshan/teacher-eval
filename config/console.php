<?php
use think\facade\Env;

// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'install'        => \app\command\Install::class,
        'period:switch'  => \app\command\PeriodSwitch::class,
        'admin:password' => \app\command\AdminPassword::class,
    ],
];
