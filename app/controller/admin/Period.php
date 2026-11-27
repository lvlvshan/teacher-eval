<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\PeriodService;
use app\service\QuestionnaireService;
use app\service\IpService;
use think\facade\Db;

/**
 * 周期 / 测评对象 / 场次 / 归档
 */
class Period extends BaseController
{
    public function index()
    {
        $rows = Db::name('period')->order('year desc, month desc')->select()->toArray();
        $list = [];
        foreach ($rows as $p) {
            $p['target_count']  = (int) Db::name('period_target')->where('period_id', $p['id'])->count();
            $p['submit_count']  = (int) Db::name('submission')->where('period_id', $p['id'])->count();
            $p['slot_count']    = (int) Db::name('eval_slot')->where('period_id', $p['id'])->count();
            $list[] = $p;
        }
        $teachers = Db::name('teacher')->where('status', 1)->order('name asc')->select()->toArray();
        $classes = Db::name('klass')->where('status', 1)->order('name asc')->select()->toArray();
        $courses = Db::name('course')->where('status', 1)->order('name asc')->select()->toArray();
        $startDefault = date('Y-m-d\TH:i');
        $endDefault = date('Y-m-t\T23:59');
        return view('admin/periods', compact('list', 'teachers', 'classes', 'courses', 'startDefault', 'endDefault'));
    }

    public function save()
    {
        $id = (int) $this->request->post('id', 0);
        $year = (int) $this->request->post('year', date('Y'));
        $month = (int) $this->request->post('month', date('n'));
        $data = [
            'year'        => $year,
            'month'       => $month,
            'name'        => trim((string) $this->request->post('name', '')) ?: ($year . '年' . $month . '月'),
            'starts_at'   => $this->normalizeTime((string) $this->request->post('starts_at', '')),
            'ends_at'     => $this->normalizeTime((string) $this->request->post('ends_at', '')),
            'update_time' => date('Y-m-d H:i:s'),
        ];
        if ($month < 1 || $month > 12) {
            return $this->back('/admin/periods', 'err', '月份不合法');
        }
        if ($id === 0 && $data['starts_at'] === '') {
            // 未填开始时间时，默认从创建时刻开始
            $data['starts_at'] = date('Y-m-d H:i:s');
        }

        if ($id > 0) {
            $p = Db::name('period')->where('id', $id)->find();
            if ($p && $p['state'] === 'archived') {
                return $this->back('/admin/periods', 'err', '已归档周期不可修改，请先解冻');
            }
            Db::name('period')->where('id', $id)->update($data);
            return $this->back('/admin/periods', 'ok', '已保存');
        }

        $exist = Db::name('period')->where('year', $year)->where('month', $month)->find();
        if ($exist) {
            return $this->back('/admin/periods', 'err', '该月份周期已存在');
        }

        $teacherId = (int) $this->request->post('add_teacher_id', 0);
        $classId   = (int) $this->request->post('add_class_id', 0);
        $courseId  = (int) $this->request->post('add_course_id', 0);
        $cidrInput = trim((string) $this->request->post('add_cidrs', ''));

        if ($teacherId || $classId || $courseId) {
            if (!$teacherId || !$classId || !$courseId) {
                return $this->back('/admin/periods', 'err', '请完整选择教员、班级、课程');
            }
        }
        if ($classId && $cidrInput === '') {
            return $this->back('/admin/periods', 'err', '请填写本班 IP 段（如 10.10.1.0/24），学生按它自动进入本班');
        }
        $parsed = IpService::parseCidrs($cidrInput);
        if ($parsed['bad']) {
            return $this->back('/admin/periods', 'err', 'IP 段格式错误：' . implode('、', $parsed['bad']));
        }

        $data['state'] = 'open';
        $data['create_time'] = date('Y-m-d H:i:s');
        $pid = (int) Db::name('period')->insertGetId($data);

        QuestionnaireService::ensureForPeriod($pid);
        $added = PeriodService::addTargets($pid, [
            [
                'teacher_id' => $teacherId,
                'class_id'   => $classId,
                'course_id'  => $courseId,
            ],
        ]);
        if ($classId && $parsed['valid']) {
            IpService::setClassCidrs($pid, $classId, $parsed['valid'], '创建测评时设置');
        }

        return $this->back('/admin/periods', 'ok', "周期已创建，新增 {$added} 条测评对象");
    }

    public function changeState()
    {
        $id = (int) $this->request->post('id', 0);
        $state = (string) $this->request->post('state', '');
        if (!in_array($state, ['pending', 'open', 'closed'], true)) {
            return $this->back('/admin/periods', 'err', '状态不合法');
        }
        $p = Db::name('period')->where('id', $id)->find();
        if (!$p) {
            return $this->back('/admin/periods', 'err', '周期不存在');
        }
        if ($p['state'] === 'archived') {
            return $this->back('/admin/periods', 'err', '已归档周期不可改状态，请先解冻');
        }
        Db::name('period')->where('id', $id)->update(['state' => $state, 'update_time' => date('Y-m-d H:i:s')]);
        $this->audit('period_state', 'period#' . $id, ['state' => $state]);
        return $this->back('/admin/periods', 'ok', '状态已更新为 ' . ev_state_text($state));
    }

    /* ---------------- 测评对象 ---------------- */

    public function targets($id)
    {
        $id = (int) $id;
        $period = Db::name('period')->where('id', $id)->find();
        if (!$period) {
            return redirect('/admin/periods');
        }
        $list = PeriodService::targets($id);
        $teachers = Db::name('teacher')->where('status', 1)->order('name asc')->select()->toArray();
        $classes = Db::name('klass')->where('status', 1)->order('name asc')->select()->toArray();
        $courses = Db::name('course')->where('status', 1)->order('name asc')->select()->toArray();

        $period['target_count'] = count($list);

        $grouped = [];
        foreach ($list as $t) {
            $grouped[$t['class_id']]['class_id'] = (int) $t['class_id'];
            $grouped[$t['class_id']]['class_name'] = $t['class_name'];
            $grouped[$t['class_id']]['items'][] = $t;
        }

        $ipMap = IpService::classCidrs($id);
        foreach ($grouped as $cid => &$g) {
            $g['cidrs'] = implode(', ', $ipMap[$cid] ?? []);
        }
        unset($g);

        return view('admin/targets', compact('period', 'list', 'grouped', 'teachers', 'classes', 'courses'));
    }

    public function saveTargets($id)
    {
        $id = (int) $id;
        $p = Db::name('period')->where('id', $id)->find();
        if (!$p) {
            return $this->back('/admin/periods', 'err', '周期不存在');
        }
        if ($p['state'] === 'archived') {
            return $this->back('/admin/periods/' . $id . '/targets', 'err', '已归档周期不可修改');
        }

        // 新增单条
        $addTeacher = (int) $this->request->post('add_teacher_id', 0);
        if ($addTeacher) {
            $data = [
                'period_id'  => $id,
                'teacher_id' => $addTeacher,
                'class_id'   => (int) $this->request->post('add_class_id', 0),
                'course_id'  => (int) $this->request->post('add_course_id', 0),
                'state'      => 'required',
                'sort'       => 0,
            ];
            if ($data['class_id'] && $data['course_id']) {
                $exist = Db::name('period_target')->where([
                    'period_id' => $id, 'class_id' => $data['class_id'],
                    'course_id' => $data['course_id'], 'teacher_id' => $addTeacher,
                ])->find();
                if (!$exist) {
                    Db::name('period_target')->insert($data);
                }
            }
        }

        // 批量修改状态
        $states = $this->request->post('state', []);
        $reasons = $this->request->post('exclude_reason', []);
        $sorts = $this->request->post('sort', []);
        if (is_array($states)) {
            foreach ($states as $tid => $st) {
                $tid = (int) $tid;
                if (!$tid) {
                    continue;
                }
                if (!in_array($st, ['required', 'optional', 'excluded'], true)) {
                    continue;
                }
                Db::name('period_target')->where('id', $tid)->where('period_id', $id)->update([
                    'state'          => $st,
                    'exclude_reason' => mb_substr(trim((string) ($reasons[$tid] ?? '')), 0, 100),
                    'sort'           => (int) ($sorts[$tid] ?? 0),
                ]);
            }
        }

        // 删除标记
        $remove = $this->request->post('remove', []);
        if (is_array($remove)) {
            foreach ($remove as $tid => $on) {
                if ($on) {
                    Db::name('period_target')->where('id', (int) $tid)->where('period_id', $id)->delete();
                }
            }
        }

        $this->audit('period_targets_save', 'period#' . $id, []);
        return $this->back('/admin/periods/' . $id . '/targets', 'ok', '已保存测评对象名单');
    }

    /* ---------------- 班级 IP 段 ---------------- */

    public function saveIp($id)
    {
        $id = (int) $id;
        $p = Db::name('period')->where('id', $id)->find();
        if (!$p) {
            return $this->back('/admin/periods', 'err', '周期不存在');
        }
        if ($p['state'] === 'archived') {
            return $this->back('/admin/periods/' . $id . '/targets', 'err', '已归档周期不可修改，请先解冻');
        }
        $classId = (int) $this->request->post('class_id', 0);
        if (!$classId) {
            return $this->back('/admin/periods/' . $id . '/targets', 'err', '请选择班级');
        }
        $parsed = IpService::parseCidrs((string) $this->request->post('cidrs', ''));
        if ($parsed['bad']) {
            return $this->back('/admin/periods/' . $id . '/targets', 'err', 'IP 段格式错误：' . implode('、', $parsed['bad']));
        }
        $saved = IpService::setClassCidrs($id, $classId, $parsed['valid'], '测评名单页设置');
        $this->audit('period_ip_save', 'period#' . $id, ['class_id' => $classId, 'cidrs' => $parsed['valid']]);
        return $this->back('/admin/periods/' . $id . '/targets', 'ok', $saved ? "已保存 {$saved} 个 IP 段" : '已清空该班 IP 段');
    }

    /* ---------------- 场次 ---------------- */

    public function slots($id)
    {
        $id = (int) $id;
        $period = Db::name('period')->where('id', $id)->find();
        if (!$period) {
            return redirect('/admin/periods');
        }
        $list = Db::name('eval_slot')
            ->alias('s')
            ->join('klass k', 'k.id = s.class_id')
            ->where('s.period_id', $id)
            ->field('s.*, k.name as class_name')
            ->order('s.starts_at', 'asc')
            ->select()->toArray();

        // 实交数
        $cnt = Db::name('submission')->where('period_id', $id)->whereRaw('excluded = 0')
            ->field('slot_id, COUNT(*) as c')->group('slot_id')->select()->toArray();
        $cntMap = [];
        foreach ($cnt as $c) {
            $cntMap[(int) $c['slot_id']] = (int) $c['c'];
        }

        $now = date('Y-m-d H:i:s');
        foreach ($list as $i => $s) {
            $list[$i]['actual'] = $cntMap[(int) $s['id']] ?? 0;
            $list[$i]['rate']   = null;
            $list[$i]['rate_text'] = '—';
            if ($s['ends_at'] < $now) {
                $list[$i]['status_text']  = '已结束';
                $list[$i]['status_class'] = '';
            } elseif ($s['starts_at'] > $now) {
                $list[$i]['status_text']  = '未开始';
                $list[$i]['status_class'] = 'tag-info';
            } else {
                $list[$i]['status_text']  = '进行中';
                $list[$i]['status_class'] = 'tag-ok';
            }
        }

        $classes = Db::name('klass')->where('status', 1)->order('name asc')->select()->toArray();
        return view('admin/slots', compact('period', 'list', 'classes', 'cntMap'));
    }

    public function saveSlot($id)
    {
        $id = (int) $id;
        $sid = (int) $this->request->post('slot_id', 0);
        $data = [
            'period_id'      => $id,
            'class_id'       => (int) $this->request->post('class_id', 0),
            'lab_room'       => trim((string) $this->request->post('lab_room', '')),
            'starts_at'      => $this->normalizeTime((string) $this->request->post('starts_at', '')),
            'ends_at'        => $this->normalizeTime((string) $this->request->post('ends_at', '')),
            'state'          => 'open',
        ];
        if (!$data['class_id'] || !$data['starts_at'] || !$data['ends_at']) {
            return $this->back('/admin/periods/' . $id . '/slots', 'err', '班级和起止时间必填');
        }
        if ($data['ends_at'] <= $data['starts_at']) {
            return $this->back('/admin/periods/' . $id . '/slots', 'err', '结束时间必须晚于开始时间');
        }

        // 机房冲突检测
        $conflict = Db::name('eval_slot')
            ->where('period_id', $id)
            ->where('lab_room', $data['lab_room'])
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->when($sid > 0, function ($q) use ($sid) {
                $q->where('id', '<>', $sid);
            })
            ->find();
        if ($conflict && $data['lab_room'] !== '') {
            return $this->back('/admin/periods/' . $id . '/slots', 'err', '该机房在此时段已有场次，请调整时间');
        }

        if ($sid > 0) {
            Db::name('eval_slot')->where('id', $sid)->where('period_id', $id)->update($data);
        } else {
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::name('eval_slot')->insert($data);
        }
        return $this->back('/admin/periods/' . $id . '/slots', 'ok', '已保存场次');
    }

    public function deleteSlot($id)
    {
        $id = (int) $id;
        $sid = (int) $this->request->post('slot_id', 0);
        Db::name('eval_slot')->where('id', $sid)->where('period_id', $id)->delete();
        return $this->back('/admin/periods/' . $id . '/slots', 'ok', '已删除');
    }

    /* ---------------- 归档 ---------------- */

    public function archive($id)
    {
        $id = (int) $id;
        try {
            $r = PeriodService::archive($id, (int) session('admin_id'));
            $this->audit('archive', 'period#' . $id, $r);
            return $this->back('/admin/periods', 'ok', "已归档（v{$r['version']}），教员 {$r['target_count']} 人、IP 规则 {$r['ip_count']} 条，哈希已生成");
        } catch (\Throwable $e) {
            return $this->back('/admin/periods', 'err', $e->getMessage());
        }
    }

    public function unarchive($id)
    {
        $id = (int) $id;
        if (session('admin_role') !== 'super') {
            return $this->back('/admin/periods', 'err', '只有超级管理员可以解冻');
        }
        $reason = trim((string) $this->request->post('reason', ''));
        if ($reason === '') {
            return $this->back('/admin/periods', 'err', '解冻必须填写原因');
        }
        try {
            PeriodService::unarchive($id, (int) session('admin_id'), $reason);
            return $this->back('/admin/periods', 'ok', '已解冻，原快照已留痕');
        } catch (\Throwable $e) {
            return $this->back('/admin/periods', 'err', $e->getMessage());
        }
    }

    /* ---------------- 工具 ---------------- */

    protected function normalizeTime(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        $ts = strtotime(str_replace('T', ' ', $v));
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
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
