<?php
declare(strict_types=1);

namespace app\command;

use app\service\InstallService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class Install extends Command
{
    protected function configure()
    {
        $this->setName('install')
            ->addOption('demo', null, Option::VALUE_NONE, '同时写入示例数据')
            ->setDescription('初始化数据库（SQLite）');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            $log = InstallService::run((bool) $input->getOption('demo'));
            foreach ($log as $line) {
                $output->writeln('  [ok] ' . $line);
            }
            $output->writeln('<info>初始化完成。后台账号 admin / admin123</info>');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>初始化失败：' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}
