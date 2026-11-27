<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 学生端作答逻辑
 */
class EvaluateService
{
    /** 会话过期宽限（秒） */
    public const GRACE = 600;

    /**
     * 解析学生端班级上下文：当前进行中的周期 + 班级
     */
    public static function resolveClass(int $classId): ?array
    {
        $klass = Db::name('klass')->where('id', $classId)->find();
        $period = PeriodService::current();
        if (!$klass || !$period) {
            return null;
        }
        return ['period' => $period, 'klass' => $klass];
    }

    /**
     * 周期是否处于可作答状态
     */
    public static function periodOpen(array $period): bool
    {
        if ($period['state'] !== 'open') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if ($period['starts_at'] && $period['starts_at'] > $now) {
            return false;
        }
        if ($period['ends_at'] && $period['ends_at'] < $now) {
            return false;
        }
        return true;
    }

    /**
     * 取或创建匿名会话
     */
    public static function touchSession(int $periodId, int $classId, ?int $slotId): array
    {
        $ip = ev_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ipHash = ev_hash($ip);
        $uaHash = ev_hash($ua);

        $token = session('ev_sid');
        $session = null;
        if ($token) {
            $session = Db::name('eval_session')
                ->where('session_token', $token)
                ->where('period_id', $periodId)
                ->where('class_id', $classId)
                ->find();
        }

        $now = time();
        // 会话仍有效则复用
        if ($session && strtotime($session['expires_at']) + self::GRACE > $now && $session['state'] === 'active') {
            return $session;
        }

        // 计算时限：min(30 分钟, 场次剩余)
        $ttl = (int) env('eval.session_ttl', 30) * 60;
        $expires = $now + $ttl;
        if ($slotId) {
            $slot = Db::name('eval_slot')->where('id', $slotId)->find();
            if ($slot) {
                $expires = min($expires, strtotime($slot['ends_at']));
            }
        }
        $newToken = md5(uniqid('', true) . $ipHash . mt_rand());
        $id = (int) Db::name('eval_session')->insertGetId([
            'period_id'      => $periodId,
            'class_id'       => $classId,
            'slot_id'        => $slotId,
            'session_token'  => $newToken,
            'ip_hash'        => $ipHash,
            'ua_hash'        => $uaHash,
            'started_at'     => date('Y-m-d H:i:s', $now),
            'expires_at'     => date('Y-m-d H:i:s', $expires),
            'submitted_count' => 0,
            'state'          => 'active',
            'create_time'    => date('Y-m-d H:i:s', $now),
        ]);
        session('ev_sid', $newToken);

        return Db::name('eval_session')->where('id', $id)->find();
    }

    /**
     * 本班本周期可评的测评对象
     */
    public static function targetsForClass(int $periodId, int $classId): array
    {
        return Db::name('period_target')
            ->alias('pt')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->join('course c', 'c.id = pt.course_id')
            ->where('pt.period_id', $periodId)
            ->where('pt.class_id', $classId)
            ->whereIn('pt.state', ['required', 'optional'])
            ->field('pt.id, pt.teacher_id, pt.course_id, pt.state, pt.sort, t.name as teacher_name, t.subject, c.name as course_name')
            ->order('pt.sort asc, c.name asc, t.name asc')
            ->select()->toArray();
    }

    /**
     * 本设备（IP + UA 指纹 ≈ MAC；浏览器端无法读取 MAC，故以 IP+UA 作为设备身份）在本班本周期已提交过的 target_id
     */
    public static function submittedTargetIds(int $periodId, int $classId, string $ipHash, string $uaHash): array
    {
        return Db::name('submission')
            ->where('period_id', $periodId)
            ->where('class_id', $classId)
            ->where('ip_hash', $ipHash)
            ->where('ua_hash', $uaHash)
            ->column('target_id');
    }

    /**
     * 当前设备指纹（IP + UA）
     */
    public static function deviceFinger(): array
    {
        $ip = ev_client_ip();
        return [
            'ip_hash' => ev_hash($ip),
            'ua_hash' => ev_hash($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'      => $ip,
        ];
    }

    /**
     * 取单个测评对象
     */
    public static function findTarget(int $periodId, int $classId, int $targetId): ?array
    {
        return Db::name('period_target')
            ->alias('pt')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->join('course c', 'c.id = pt.course_id')
            ->where('pt.id', $targetId)
            ->where('pt.period_id', $periodId)
            ->where('pt.class_id', $classId)
            ->field('pt.*, t.name as teacher_name, t.subject, c.name as course_name')
            ->find() ?: null;
    }

    /**
     * 提交作答
     *
     * @param array $scores  question_id => score
     * @param array $texts   question_id => content
     * @return array{ok:bool,message:string,submission_id?:int}
     */
    public static function submit(array $ctx, array $target, array $session, array $scores, array $texts): array
    {
        $period = $ctx['period'];
        $classId = (int) $ctx['klass']['id'];
        $periodId = (int) $period['id'];

        if (!self::periodOpen($period)) {
            return ['ok' => false, 'message' => '本次测评已结束'];
        }

        // 已提交过（按设备 IP+UA 标识：每个独立的问卷，每台设备只允许提交一次）
        $finger = self::deviceFinger();
        $exist = Db::name('submission')
            ->where('period_id', $periodId)
            ->where('target_id', $target['id'])
            ->where('ip_hash', $finger['ip_hash'])
            ->where('ua_hash', $finger['ua_hash'])
            ->find();
        if ($exist) {
            return ['ok' => false, 'message' => '重复提交：该问卷已由本设备提交过，请勿重复作答'];
        }

        $structure = QuestionnaireService::structure($periodId);
        if (!$structure) {
            return ['ok' => false, 'message' => '问卷不存在，请联系老师'];
        }
        $q = $structure['questionnaire'];
        $scaleMax = (int) $q['scale_max'];

        $ratingQuestions = array_filter($structure['questions'], function ($x) {
            return $x['type'] === 'rating';
        });
        $textQuestions = array_filter($structure['questions'], function ($x) {
            return $x['type'] === 'text';
        });

        // 校验必答
        $clean = [];
        foreach ($ratingQuestions as $qq) {
            $qid = (int) $qq['id'];
            if (!isset($scores[$qid]) || $scores[$qid] === '') {
                return ['ok' => false, 'message' => '还有题目没有评分，请全部作答后再提交'];
            }
            $v = (int) $scores[$qid];
            if ($v < 1 || $v > $scaleMax) {
                return ['ok' => false, 'message' => '评分超出范围'];
            }
            $clean[$qid] = $v;
        }

        // 计算得分
        $sum = 0;
        $count = 0;
        $weightSum = 0;
        $allSame = true;
        $first = null;
        foreach ($ratingQuestions as $qq) {
            $qid = (int) $qq['id'];
            $w = (float) $qq['weight'];
            if ($w <= 0) {
                $w = 1;
            }
            $sum += $clean[$qid] * $w;
            $weightSum += $w;
            $count++;
            if ($first === null) {
                $first = $clean[$qid];
            } elseif ($clean[$qid] !== $first) {
                $allSame = false;
            }
        }
        if ($count === 0 || $weightSum <= 0) {
            return ['ok' => false, 'message' => '问卷题目配置异常'];
        }
        $weightedAvg = $sum / $weightSum;
        $total = round($weightedAvg * $count, 2);
        $avg = round($weightedAvg, 2);

        // 时长与异常标记
        $startedAt = strtotime($session['started_at']);
        $now = time();
        $durationMs = max(0, ($now - $startedAt) * 1000);
        $flag = 'ok';
        if ($now > strtotime($session['expires_at'])) {
            $flag = 'timeout';
        } elseif ($allSame) {
            $flag = 'uniform';
        } elseif ($durationMs < 20000) {
            $flag = 'fast';
        }

        $ip = ev_client_ip();
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $macInfo = ev_client_mac($ip);
        Db::startTrans();
        try {
            $sid = (int) Db::name('submission')->insertGetId([
                'period_id'        => $periodId,
                'target_id'        => $target['id'],
                'teacher_id'       => $target['teacher_id'],
                'class_id'         => $classId,
                'course_id'        => $target['course_id'],
                'slot_id'          => $session['slot_id'],
                'session_id'       => $session['id'],
                'questionnaire_id' => $q['id'],
                'ip_hash'          => $finger['ip_hash'],
                'ip_masked'        => ev_mask_ip($ip),
                'ua_hash'          => $finger['ua_hash'],
                'ip'               => $ip,
                'ua'               => mb_substr($ua, 0, 255),
                'mac'              => $macInfo['mac'],
                'mac_source'       => $macInfo['source'],
                'device_code'      => strtoupper(substr(ev_hash($ip . '|' . $ua), 0, 8)),
                'total_score'      => $total,
                'avg_score'        => $avg,
                'duration_ms'      => $durationMs,
                'flag'             => $flag,
                'reviewed'         => 0,
                'excluded'         => 0,
                'submitted_at'     => date('Y-m-d H:i:s'),
            ]);

            $rows = [];
            foreach ($ratingQuestions as $qq) {
                $qid = (int) $qq['id'];
                $rows[] = [
                    'submission_id' => $sid,
                    'question_id'   => $qid,
                    'dimension_id'  => $qq['dimension_id'],
                    'score'         => $clean[$qid],
                    'content'       => null,
                ];
            }
            foreach ($textQuestions as $qq) {
                $qid = (int) $qq['id'];
                $content = trim((string) ($texts[$qid] ?? ''));
                if ($content === '') {
                    continue;
                }
                $rows[] = [
                    'submission_id' => $sid,
                    'question_id'   => $qid,
                    'dimension_id'  => null,
                    'score'         => null,
                    'content'       => mb_substr($content, 0, 1000),
                ];
            }
            if ($rows) {
                Db::name('answer')->insertAll($rows);
            }

            Db::name('eval_session')->where('id', $session['id'])->inc('submitted_count')->update();
            Db::commit();

            return ['ok' => true, 'message' => '提交成功', 'submission_id' => $sid];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'message' => '提交失败：' . $e->getMessage()];
        }
    }
}
