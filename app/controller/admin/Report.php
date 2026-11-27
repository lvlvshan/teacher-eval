<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\ExportService;
use app\service\ReportService;
use think\facade\Db;

class Report extends BaseController
{
    public function dashboard()
    {
        $periods = Db::name('period')->order('year desc, month desc')->select()->toArray();
        $pid = (int) $this->request->get('period_id', 0);
        if (!$pid && $periods) {
            $pid = (int) $periods[0]['id'];
        }
        $period = $pid ? Db::name('period')->where('id', $pid)->find() : null;
        $data = $pid ? ReportService::dashboard($pid) : [
            'rows' => [], 'dimensions' => [], 'total_submissions' => 0,
            'target_count' => 0, 'anomaly' => 0,
        ];

        return view('admin/dashboard', [
            'periods' => $periods,
            'pid'     => $pid,
            'period'  => $period,
            'data'    => $data,
        ]);
    }

    public function detail()
    {
        $periods = Db::name('period')->order('year desc, month desc')->select()->toArray();
        $pid = (int) $this->request->get('period_id', 0);
        if (!$pid && $periods) {
            $pid = (int) $periods[0]['id'];
        }
        $period = $pid ? Db::name('period')->where('id', $pid)->find() : null;
        $filters = [
            'teacher_id' => (int) $this->request->get('teacher_id', 0),
            'flag'       => (string) $this->request->get('flag', ''),
        ];
        $rows = $pid ? ReportService::details($pid, $filters) : [];
        $teachers = $pid ? Db::name('period_target')->alias('pt')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->where('pt.period_id', $pid)->group('pt.teacher_id')
            ->field('t.id, t.name')->select()->toArray() : [];

        return view('admin/detail', compact('periods', 'pid', 'period', 'rows', 'teachers', 'filters'));
    }

    /**
     * 投票详情：按设备（机房一台电脑 ≈ 一名学生）汇总查看提交记录
     */
    public function votes()
    {
        $periods = Db::name('period')->order('year desc, month desc')->select()->toArray();
        $pid = (int) $this->request->get('period_id', 0);
        if (!$pid && $periods) {
            $pid = (int) $periods[0]['id'];
        }
        $period = $pid ? Db::name('period')->where('id', $pid)->find() : null;
        $filters = [
            'class_id'   => (int) $this->request->get('class_id', 0),
            'teacher_id' => (int) $this->request->get('teacher_id', 0),
            'course_id'  => (int) $this->request->get('course_id', 0),
            'flag'       => (string) $this->request->get('flag', ''),
            'keyword'    => trim((string) $this->request->get('keyword', '')),
        ];
        $devices = $pid ? ReportService::voteDevices($pid, $filters) : [];
        $submissionCount = $pid ? (int) Db::name('submission')->where('period_id', $pid)->count() : 0;
        $anomalies = $pid ? (int) Db::name('submission')->where('period_id', $pid)
            ->whereIn('flag', ['fast', 'uniform'])->where('excluded', 0)->count() : 0;

        $classes = Db::name('klass')->where('status', 1)->order('name', 'asc')->select()->toArray();
        $courses = Db::name('course')->where('status', 1)->order('name', 'asc')->select()->toArray();
        $teachers = $pid ? Db::name('period_target')->alias('pt')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->where('pt.period_id', $pid)->group('pt.teacher_id')
            ->field('t.id, t.name')->select()->toArray()
            : Db::name('teacher')->where('status', 1)->order('name', 'asc')->select()->toArray();

        return view('admin/votes', compact(
            'periods', 'pid', 'period', 'filters', 'devices', 'anomalies', 'submissionCount',
            'classes', 'courses', 'teachers'
        ));
    }

    public function voteDetail(int $id = 0)
    {
        $id = (int) ($id ?: $this->request->get('id', 0));
        $vote = $id ? ReportService::voteDetail($id) : null;
        if (!$vote) {
            return $this->back('/admin/votes', 'err', '投票记录不存在');
        }
        $this->audit('view_vote', 'submission#' . $id, [
            'period_id'   => (int) $vote['period_id'],
            'device_code' => $vote['device_code'],
        ]);
        return view('admin/vote_detail', compact('vote'));
    }

    public function review()
    {
        $id = (int) $this->request->post('id', 0);
        $valid = (int) $this->request->post('valid', 0) === 1;
        $note = trim((string) $this->request->post('note', ''));
        $pid = (int) $this->request->post('period_id', 0);
        try {
            ReportService::review($id, (int) session('admin_id'), $valid, $note);
            return $this->back('/admin/detail?period_id=' . $pid, 'ok', $valid ? '已恢复计入统计' : '已剔除统计');
        } catch (\Throwable $e) {
            return $this->back('/admin/detail?period_id=' . $pid, 'err', $e->getMessage());
        }
    }

    public function exportPage()
    {
        $periods = Db::name('period')->order('year desc, month desc')->select()->toArray();
        $years = array_values(array_unique(array_column($periods, 'year')));
        rsort($years);
        $teachers = Db::name('teacher')->where('status', 1)->order('name asc')->select()->toArray();
        $courses = Db::name('course')->where('status', 1)->order('name asc')->select()->toArray();
        // 每个教员参与过的课程
        $tc = Db::name('submission')->field('teacher_id, course_id')->group('teacher_id, course_id')->select()->toArray();

        return view('admin/export', compact('periods', 'years', 'teachers', 'courses', 'tc'));
    }

    public function exportAnnual()
    {
        $year = (int) $this->request->post('year', date('Y'));
        $teacherId = (int) $this->request->post('teacher_id', 0);
        $courseId = (int) $this->request->post('course_id', 0) ?: null;

        try {
            if ($teacherId > 0) {
                $path = ExportService::annualPath($year, $teacherId, $courseId);
                $name = ExportService::annualName($year, $teacherId, $courseId);
                $this->audit('export_annual', 'teacher#' . $teacherId, ['year' => $year, 'course_id' => $courseId]);
                return download($path, $name);
            }

            // 全教员打包 zip
            $zipPath = ExportService::annualAllZip($year);
            $this->audit('export_annual_all', 'year#' . $year, []);
            return download($zipPath, "学员满意度得分-{$year}年-全部教员.zip");
        } catch (\Throwable $e) {
            return $this->back('/admin/export', 'err', '导出失败：' . $e->getMessage());
        }
    }

    public function exportPack()
    {
        $year = (int) $this->request->post('year', date('Y'));
        $withArchive = (bool) $this->request->post('with_archive', 0);
        try {
            $path = ExportService::packPath($year, $withArchive);
            $this->audit('export_pack', 'year#' . $year, ['with_archive' => $withArchive]);
            return download($path, "学员满意度汇总包-{$year}年.xlsx");
        } catch (\Throwable $e) {
            return $this->back('/admin/export', 'err', '导出失败：' . $e->getMessage());
        }
    }

    protected function back(string $url, string $type, string $msg)
    {
        session('flash', ['type' => $type, 'msg' => $msg]);
        return redirect($url);
    }

    protected function audit(string $action, string $target, array $detail)
    {
        Db::name('audit_log')->insert([
            'admin_id'    => (int) session('admin_id'),
            'action'      => $action,
            'target'      => $target,
            'detail'      => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip_hash'     => ev_hash(ev_client_ip()),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
