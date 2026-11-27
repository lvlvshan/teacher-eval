<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 周期、测评对象快照、场次、归档冻结
 */
class PeriodService
{
    /**
     * 新增测评对象（教员 + 班级 + 课程），由管理员在创建周期时直接指定
     *
     * @param array<int, array{teacher_id?:int, class_id?:int, course_id?:int}> $pairs
     */
    public static function addTargets(int $periodId, array $pairs): int
    {
        $added = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($pairs as $p) {
            $teacherId = (int) ($p['teacher_id'] ?? 0);
            $classId = (int) ($p['class_id'] ?? 0);
            $courseId = (int) ($p['course_id'] ?? 0);
            if (!$teacherId || !$classId || !$courseId) {
                continue;
            }
            $exist = Db::name('period_target')
                ->where('period_id', $periodId)
                ->where('teacher_id', $teacherId)
                ->where('class_id', $classId)
                ->where('course_id', $courseId)
                ->find();
            if ($exist) {
                continue;
            }
            Db::name('period_target')->insert([
                'period_id'  => $periodId,
                'teacher_id' => $teacherId,
                'class_id'   => $classId,
                'course_id'  => $courseId,
                'state'      => 'required',
                'sort'       => 0,
            ]);
            $added++;
        }
        return $added;
    }

    /**
     * 当前进行中的周期（按年月倒序取最新一个）
     */
    public static function current(): ?array
    {
        $now = date('Y-m-d H:i:s');
        $row = Db::name('period')
            ->where('state', 'open')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->order('year desc, month desc, id desc')
            ->find();
        return $row ?: null;
    }

    /**
     * 周期内全部测评对象（含姓名）
     */    public static function targets(int $periodId): array
    {
        return Db::name('period_target')
            ->alias('pt')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->join('klass k', 'k.id = pt.class_id')
            ->join('course c', 'c.id = pt.course_id')
            ->where('pt.period_id', $periodId)
            ->field('pt.*, t.name as teacher_name, t.employee_no, k.name as class_name, c.name as course_name')
            ->order('pt.class_id asc, pt.sort asc, pt.id asc')
            ->select()->toArray();
    }

    /**
     * 当前进行中的场次（若该周期未排场次则返回 null，表示不限制）
     */
    public static function activeSlot(int $periodId, int $classId): ?array
    {
        $total = Db::name('eval_slot')->where('period_id', $periodId)->count();
        if ((int) $total === 0) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        return Db::name('eval_slot')
            ->where('period_id', $periodId)
            ->where('class_id', $classId)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->order('id', 'desc')
            ->find() ?: null;
    }

    /**
     * 班级今日待开始/已结束的场次提示
     */
    public static function nextSlot(int $periodId, int $classId): ?array
    {
        return Db::name('eval_slot')
            ->where('period_id', $periodId)
            ->where('class_id', $classId)
            ->where('ends_at', '>=', date('Y-m-d H:i:s'))
            ->order('starts_at', 'asc')
            ->find() ?: null;
    }

    /**
     * 归档：冻结问卷、名单、IP 规则，生成快照与哈希
     */
    public static function archive(int $periodId, int $adminId): array
    {
        $period = Db::name('period')->where('id', $periodId)->find();
        if (!$period) {
            throw new \RuntimeException('周期不存在');
        }
        if ($period['state'] === 'archived') {
            throw new \RuntimeException('该周期已归档');
        }

        $ipRules = Db::name('ip_rule')
            ->where('active', 1)
            ->where(function ($q) use ($periodId) {
                $q->where('scope', 'global')
                  ->whereOr(function ($q2) use ($periodId) {
                      $q2->where('scope', 'period')->where('period_id', $periodId);
                  })
                  ->whereOr(function ($q3) use ($periodId) {
                      $q3->where('scope', 'class')->where(function ($q4) use ($periodId) {
                          $q4->where('period_id', $periodId)->whereOr('period_id', null);
                      });
                  });
            })
            ->field('id,scope,period_id,class_id,action,cidr,note')
            ->order('id', 'asc')
            ->select()->toArray();

        $targets = Db::name('period_target')
            ->alias('pt')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->join('klass k', 'k.id = pt.class_id')
            ->join('course c', 'c.id = pt.course_id')
            ->where('pt.period_id', $periodId)
            ->field('pt.id,pt.teacher_id,pt.class_id,pt.course_id,pt.state,pt.exclude_reason,t.name as teacher_name,t.employee_no,k.name as class_name,c.name as course_name')
            ->order('pt.id', 'asc')
            ->select()->toArray();

        $q = Db::name('questionnaire')->where('period_id', $periodId)->find();
        $questionnaire = null;
        if ($q) {
            $questionnaire = [
                'questionnaire' => $q,
                'dimensions'    => Db::name('dimension')->where('questionnaire_id', $q['id'])->order('sort', 'asc')->select()->toArray(),
                'questions'     => Db::name('question')->where('questionnaire_id', $q['id'])->order('sort', 'asc')->select()->toArray(),
            ];
        }

        $rosterSnapshot = [
            'period'        => $period,
            'ip_rules'      => $ipRules,
            'targets'       => $targets,
            'questionnaire' => $questionnaire,
            'archived_at'   => date('Y-m-d H:i:s'),
        ];

        $ipJson = json_encode($ipRules, JSON_UNESCAPED_UNICODE);
        $rosterJson = json_encode($rosterSnapshot, JSON_UNESCAPED_UNICODE);

        $version = (int) $period['archive_version'] + 1;

        Db::name('period')->where('id', $periodId)->update([
            'state'               => 'archived',
            'ip_snapshot'         => $ipJson,
            'ip_snapshot_hash'    => hash_hmac('sha256', $ipJson, ev_secret()),
            'roster_snapshot'     => $rosterJson,
            'roster_snapshot_hash' => hash_hmac('sha256', $rosterJson, ev_secret()),
            'archived_at'         => date('Y-m-d H:i:s'),
            'archived_by'         => $adminId,
            'archive_version'     => $version,
            'update_time'         => date('Y-m-d H:i:s'),
        ]);

        return [
            'version'      => $version,
            'ip_hash'      => hash_hmac('sha256', $ipJson, ev_secret()),
            'roster_hash'  => hash_hmac('sha256', $rosterJson, ev_secret()),
            'ip_count'     => count($ipRules),
            'target_count' => count($targets),
        ];
    }

    /**
     * 解冻（超级管理员）
     */
    public static function unarchive(int $periodId, int $adminId, string $reason): void
    {
        $period = Db::name('period')->where('id', $periodId)->find();
        if (!$period || $period['state'] !== 'archived') {
            throw new \RuntimeException('该周期未归档');
        }
        // 旧快照留痕
        Db::name('audit_log')->insert([
            'admin_id'    => $adminId,
            'action'      => 'unarchive',
            'target'      => 'period#' . $periodId,
            'detail'      => json_encode([
                'reason'            => $reason,
                'archive_version'   => $period['archive_version'],
                'ip_snapshot'       => $period['ip_snapshot'],
                'roster_snapshot'   => $period['roster_snapshot'],
                'ip_snapshot_hash'  => $period['ip_snapshot_hash'],
                'roster_snapshot_hash' => $period['roster_snapshot_hash'],
            ], JSON_UNESCAPED_UNICODE),
            'ip_hash'     => '',
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        Db::name('period')->where('id', $periodId)->update([
            'state'       => 'open',
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 定时任务：按时间自动开闭
     */
    public static function autoSwitch(): array
    {
        $now = date('Y-m-d H:i:s');
        $opened = Db::name('period')
            ->where('state', 'pending')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->update(['state' => 'open', 'update_time' => $now]);
        $closed = Db::name('period')
            ->where('state', 'open')
            ->where('ends_at', '<', $now)
            ->update(['state' => 'closed', 'update_time' => $now]);
        return ['opened' => $opened, 'closed' => $closed];
    }
}
