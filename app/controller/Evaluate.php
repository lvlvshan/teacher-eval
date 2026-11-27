<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\EvaluateService;
use app\service\IpService;
use app\service\PeriodService;
use app\service\QuestionnaireService;
use think\facade\Db;

class Evaluate extends BaseController
{
    /**
     * 统一入口：按学生 IP 自动识别班级并直接进入
     */
    public function home()
    {
        $period = PeriodService::current();
        if (!$period) {
            return $this->notice('暂无进行中的测评', '本期测评尚未开始或已结束，请稍后再试。');
        }

        $ip = ev_client_ip();
        if (!IpService::allowed($ip, (int) $period['id'])) {
            return $this->notice('网络不在允许范围', '当前网络（' . $ip . '）不允许访问，请在本校机房内打开。');
        }

        $rows = Db::name('period_target')
            ->alias('pt')
            ->join('klass k', 'k.id = pt.class_id')
            ->where('pt.period_id', (int) $period['id'])
            ->whereIn('pt.state', ['required', 'optional'])
            ->field('k.id, k.name, COUNT(*) as target_count')
            ->group('k.id, k.name')
            ->order('k.name asc')
            ->select()->toArray();

        // 只展示本 IP 允许参与的班级（一个网段可绑定多个班级）
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
        }
        $visibleIds = IpService::allowedClasses($ip, (int) $period['id'], $ids);
        $visible = [];
        foreach ($rows as $r) {
            if (in_array((int) $r['id'], $visibleIds, true)) {
                $visible[] = $r;
            }
        }

        if (count($visible) === 1) {
            return redirect('/c/' . $visible[0]['id']);
        }
        if (!$visible) {
            return $this->notice('没有可参与的测评', '当前网络（' . $ip . '）还没有绑定班级，请联系管理员确认 IP 段配置。');
        }

        return view('evaluate/home', [
            'period'  => $period,
            'classes' => $visible,
            'ip'      => $ip,
        ]);
    }

    public function index($cid)
    {
        $cid = (int) $cid;
        $ctx = $this->guard($cid);
        if (is_string($ctx)) {
            return $this->notice('暂时无法进入', $ctx);
        }

        $period = $ctx['period'];
        $klass = $ctx['klass'];

        $slot = PeriodService::activeSlot((int) $period['id'], (int) $klass['id']);
        $session = EvaluateService::touchSession((int) $period['id'], (int) $klass['id'], $slot ? (int) $slot['id'] : null);

        $targets = EvaluateService::targetsForClass((int) $period['id'], (int) $klass['id']);
        $finger = EvaluateService::deviceFinger();
        $submitted = EvaluateService::submittedTargetIds((int) $period['id'], (int) $klass['id'], (string) $finger['ip_hash'], (string) $finger['ua_hash']);

        $doneMap = [];
        foreach ($submitted as $sid) {
            $doneMap[(int) $sid] = true;
        }
        $doneCount = 0;
        foreach ($targets as $i => $t) {
            $targets[$i]['done'] = isset($doneMap[(int) $t['id']]);
            if ($targets[$i]['done']) {
                $doneCount++;
            }
        }

        return view('evaluate/index', [
            'classId'   => $cid,
            'period'    => $period,
            'klass'     => $klass,
            'slot'      => $slot,
            'session'   => $session,
            'targets'   => $targets,
            'submitted' => $submitted,
            'doneCount' => $doneCount,
            'expires'   => strtotime($session['expires_at']),
        ]);
    }

    public function form($cid, $target)
    {
        $cid = (int) $cid;
        $target = (int) $target;

        $ctx = $this->guard($cid);
        if (is_string($ctx)) {
            return $this->notice('暂时无法进入', $ctx);
        }
        $period = $ctx['period'];
        $klass = $ctx['klass'];

        $t = EvaluateService::findTarget((int) $period['id'], (int) $klass['id'], $target);
        if (!$t) {
            return $this->notice('找不到测评对象', '这门课可能已从本月测评名单中移除，请返回重试。');
        }
        if ($t['state'] === 'excluded') {
            return $this->notice('本月不参评', '这位教员本月不参与测评。');
        }

        $slot = PeriodService::activeSlot((int) $period['id'], (int) $klass['id']);
        $session = EvaluateService::touchSession((int) $period['id'], (int) $klass['id'], $slot ? (int) $slot['id'] : null);

        $finger = EvaluateService::deviceFinger();
        $exists = Db::name('submission')
            ->where('period_id', (int) $period['id'])
            ->where('target_id', $t['id'])
            ->where('ip_hash', $finger['ip_hash'])
            ->where('ua_hash', $finger['ua_hash'])
            ->find();
        if ($exists) {
            // 该问卷本设备已提交过 → 重复提交拦截
            return $this->notice('重复提交', '该问卷已由本设备提交过，请勿重复作答。', true);
        }

        $structure = QuestionnaireService::structure((int) $period['id']);
        if (!$structure) {
            return $this->notice('问卷未配置', '本月问卷还没有发布，请联系管理员。');
        }

        // 题号按维度顺序连续编排
        $no = 0;
        foreach ($structure['dimensions'] as $di => $d) {
            foreach ($d['questions'] as $qi => $q) {
                $structure['dimensions'][$di]['questions'][$qi]['no'] = ++$no;
            }
        }
        $structure['rating_count'] = $no;

        return view('evaluate/form', [
            'classId'   => $cid,
            'period'    => $period,
            'klass'     => $klass,
            'target'    => $t,
            'structure' => $structure,
            'scales'    => range(1, max(2, (int) $structure['questionnaire']['scale_max'])),
            'expires'   => strtotime($session['expires_at']),
        ]);
    }

    public function submit($cid, $target)
    {
        $cid = (int) $cid;
        $target = (int) $target;

        $ctx = $this->guard($cid);
        if (is_string($ctx)) {
            return $this->notice('暂时无法进入', $ctx);
        }
        $period = $ctx['period'];
        $klass = $ctx['klass'];

        $t = EvaluateService::findTarget((int) $period['id'], (int) $klass['id'], $target);
        if (!$t) {
            return $this->notice('找不到测评对象', '请返回重新选择。');
        }

        $sid = session('ev_sid');
        $session = $sid ? Db::name('eval_session')->where('session_token', $sid)->find() : null;
        if (!$session) {
            return $this->notice('会话已失效', '请返回列表，重新进入问卷。');
        }

        $scores = $this->request->post('scores', []);
        $texts = $this->request->post('texts', []);

        $r = EvaluateService::submit($ctx, $t, $session, is_array($scores) ? $scores : [], is_array($texts) ? $texts : []);
        if (!$r['ok']) {
            return $this->notice('提交失败', $r['message'], true);
        }

        return redirect('/c/' . $cid . '/done/' . $t['id']);
    }

    public function done($cid, $target)
    {
        $cid = (int) $cid;
        $target = (int) $target;

        $ctx = EvaluateService::resolveClass($cid);
        if (!$ctx) {
            return $this->notice('链接无效', '当前没有进行中的测评，请从首页重新进入。');
        }
        $t = EvaluateService::findTarget((int) $ctx['period']['id'], (int) $ctx['klass']['id'], $target);

        return view('evaluate/done', [
            'classId' => $cid,
            'target'  => $t,
            'period'  => $ctx['period'],
        ]);
    }

    public function closed()
    {
        return $this->notice('测评已结束', '本期测评通道已经关闭，感谢参与。');
    }

    /**
     * 班级入口统一校验，返回上下文或错误文案
     */
    protected function guard($classId)
    {
        $ctx = EvaluateService::resolveClass((int) $classId);
        if (!$ctx) {
            return '当前没有进行中的测评，或该班级不存在，请从首页重新进入。';
        }

        $period = $ctx['period'];
        $ip = ev_client_ip();

        if (!IpService::allowed($ip, (int) $period['id'], (int) $ctx['klass']['id'])) {
            return '当前网络不在允许范围内（' . $ip . '），请在本校机房内打开。';
        }
        $allowedIds = IpService::allowedClasses($ip, (int) $period['id'], [(int) $ctx['klass']['id']]);
        if (!in_array((int) $ctx['klass']['id'], $allowedIds, true)) {
            return '当前网络（' . $ip . '）没有该班级的测评权限，请回到首页查看可参与的调研。';
        }

        $slot = PeriodService::activeSlot((int) $period['id'], (int) $ctx['klass']['id']);
        $hasSlots = (int) Db::name('eval_slot')->where('period_id', $period['id'])->count() > 0;
        if ($hasSlots && !$slot) {
            $next = PeriodService::nextSlot((int) $period['id'], (int) $ctx['klass']['id']);
            if ($next) {
                return '本班测评时段为 ' . $next['starts_at'] . ' 至 ' . $next['ends_at'] . '（' . $next['lab_room'] . '），请按时参加。';
            }
            return '本班本期测评时段已结束，感谢参与。';
        }

        return $ctx;
    }

    protected function notice($title, $message, $back = false)
    {
        return view('evaluate/notice', [
            'title'   => $title,
            'message' => $message,
            'back'    => $back,
        ]);
    }
}
