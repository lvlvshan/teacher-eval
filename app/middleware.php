<?php
// 全局中间件定义文件
return [
    // Session初始化
    \think\middleware\SessionInit::class,
    // 模板公共变量
    \app\middleware\ShareView::class,
];
