<?php
declare(strict_types=1);

namespace app\command;

use app\service\PeriodService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class PeriodSwitch extends Command
{
    protected function configure()
    {
        $this->setName('period:switch')->setDescription('按时间自动开启/截止测评周期');
    }

    protected function execute(Input $input, Output $output)
    {
        $r = PeriodService::autoSwitch();
        $output->writeln('已开启 ' . $r['opened'] . ' 个周期，已截止 ' . $r['closed'] . ' 个周期');
        return 0;
    }
}
