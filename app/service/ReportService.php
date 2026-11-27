<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 统计、明细与导出数据
 */
class ReportService
{
    /** 计入统计的条件：管理员未人工剔除；异常答卷默认计入，仅作提示 */
    public const COUNTED = "(s.excluded = 0)";

    /* ---------------- 看板 ---------------- */

    public static function dashboard(int $periodId): array
    {
        $targets = PeriodService::targets($periodId);
        $counts = Db::name('submission')
            ->alias('s')
            ->where('s.period_id', $periodId)
            ->whereRaw(self::COUNTED)
            ->field('target_id, COUNT(*) as cnt, AVG(total_score) as avg_total, AVG(avg_score) as avg_score')
            ->group('target_id')
            ->select()->toArray();
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[(int) $c['target_id']] = $c;
        }

        $dimAvg = Db::name('submission')
            ->alias('s')
            ->join('answer a', 'a.submission_id = s.id')
            ->where('s.period_id', $periodId)
            ->whereRaw(self::COUNTED)
            ->whereNotNull('a.score')
            ->field('s.target_id, a.dimension_id, AVG(a.score) as v')
            ->group('s.target_id, a.dimension_id')
            ->select()->toArray();
        $dimMap = [];
        foreach ($dimAvg as $d) {
            $dimMap[(int) $d['target_id']][(int) $d['dimension_id']] = round((float) $d['v'], 2);
        }

        $dimensions = Db::name('dimension')
            ->where('questionnaire_id', (int) (Db::name('questionnaire')->where('period_id', $periodId)->value('id') ?: 0))
            ->order('sort', 'asc')
            ->select()->toArray();

        $rows = [];
        $totalSubmissions = 0;
        $dimIds = array_map(function ($d) {
            return (int) $d['id'];
        }, $dimensions);
        foreach ($targets as $t) {
            $c = $countMap[(int) $t['id']] ?? null;
            $cnt = $c ? (int) $c['cnt'] : 0;
            $totalSubmissions += $cnt;
            $vals = [];
            foreach ($dimIds as $did) {
                $vals[] = $dimMap[(int) $t['id']][$did] ?? null;
            }
            $rows[] = [
                'target'     => $t,
                'count'      => $cnt,
                'avg_score'  => $c ? round((float) $c['avg_score'], 2) : null,
                'avg_total'  => $c ? round((float) $c['avg_total'], 2) : null,
                'dimensions' => $dimMap[(int) $t['id']] ?? [],
                'values'     => $vals,
            ];
        }

        // 按总均分排名（有有效总分的样本参与）
        $rankable = array_filter($rows, function ($r) {
            return $r['avg_total'] !== null;
        });
        usort($rankable, function ($a, $b) {
            return $b['avg_total'] <=> $a['avg_total'];
        });
        $rank = [];
        $i = 1;
        foreach ($rankable as $r) {
            $rank[(int) $r['target']['id']] = $i++;
        }
        foreach ($rows as &$r) {
            $r['rank'] = $rank[(int) $r['target']['id']] ?? null;
        }
        unset($r);

        $anomaly = Db::name('submission')
            ->alias('s')
            ->where('s.period_id', $periodId)
            ->whereIn('s.flag', ['fast', 'uniform'])
            ->where('s.excluded', 0)
            ->count();

        return [
            'rows'              => $rows,
            'dimensions'        => $dimensions,
            'total_submissions' => $totalSubmissions,
            'target_count'      => count($targets),
            'anomaly'           => $anomaly,
        ];
    }

    /* ---------------- 明细 ---------------- */

    public static function details(int $periodId, array $filters = [], int $limit = 200): array
    {
        $q = Db::name('submission')
            ->alias('s')
            ->join('teacher t', 't.id = s.teacher_id')
            ->join('klass k', 'k.id = s.class_id')
            ->join('course c', 'c.id = s.course_id')
            ->where('s.period_id', $periodId);

        if (!empty($filters['teacher_id'])) {
            $q->where('s.teacher_id', (int) $filters['teacher_id']);
        }
        if (!empty($filters['flag'])) {
            if ($filters['flag'] === 'anomaly') {
                $q->whereIn('s.flag', ['fast', 'uniform'])->where('s.excluded', 0);
            } elseif ($filters['flag'] === 'excluded') {
                $q->where('s.excluded', 1);
            } else {
                $q->where('s.flag', $filters['flag']);
            }
        }
        $rows = $q->field('s.*, t.name as teacher_name, k.name as class_name, c.name as course_name')
            ->order('s.id', 'desc')
            ->limit($limit)
            ->select()->toArray();

        foreach ($rows as &$r) {
            $r['anon_no'] = self::anonNumber($periodId, (int) $r['id']);
        }
        unset($r);
        return $rows;
    }

    /** 同一周期内的匿名编号缓存（按提交 id 递增） */
    protected static $anonSeq = [];

    public static function anonNumber(int $periodId, int $submissionId): string
    {
        if (!isset(self::$anonSeq[$periodId])) {
            $ids = Db::name('submission')->where('period_id', $periodId)->order('id', 'asc')->column('id');
            $map = [];
            $i = 1;
            foreach ($ids as $id) {
                $map[(int) $id] = 'S' . str_pad((string) $i++, 4, '0', STR_PAD_LEFT);
            }
            self::$anonSeq[$periodId] = $map;
        }
        return self::$anonSeq[$periodId][$submissionId] ?? '';
    }

    /* ---------------- 投票详情（按设备/学生） ---------------- */

    /**
     * 按设备汇总某周期内的提交（设备 = IP + UA 指纹，一台机房电脑视为一名学生）
     */
    public static function voteDevices(int $periodId, array $filters = [], int $limit = 500): array
    {
        $q = Db::name('submission')
            ->alias('s')
            ->join('teacher t', 't.id = s.teacher_id')
            ->join('klass k', 'k.id = s.class_id')
            ->join('course c', 'c.id = s.course_id')
            ->where('s.period_id', $periodId);

        if (!empty($filters['class_id'])) {
            $q->where('s.class_id', (int) $filters['class_id']);
        }
        if (!empty($filters['teacher_id'])) {
            $q->where('s.teacher_id', (int) $filters['teacher_id']);
        }
        if (!empty($filters['course_id'])) {
            $q->where('s.course_id', (int) $filters['course_id']);
        }
        if (!empty($filters['flag'])) {
            if ($filters['flag'] === 'anomaly') {
                $q->whereIn('s.flag', ['fast', 'uniform'])->where('s.excluded', 0);
            } elseif ($filters['flag'] === 'excluded') {
                $q->where('s.excluded', 1);
            } else {
                $q->where('s.flag', $filters['flag']);
            }
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $kw = '%' . $keyword . '%';
            $q->where(function ($query) use ($kw) {
                $query->whereLike('s.ip', $kw)
                    ->whereLike('s.ip_masked', $kw, 'OR')
                    ->whereLike('s.mac', $kw, 'OR')
                    ->whereLike('s.device_code', $kw, 'OR')
                    ->whereLike('s.ua', $kw, 'OR');
            });
        }

        // 先取最近 2000 条提交，再按设备聚合，避免把整库读进内存
        $rows = $q->field('s.*, t.name as teacher_name, k.name as class_name, c.name as course_name')
            ->order('s.id', 'desc')->limit(2000)->select()->toArray();

        $groups = [];
        foreach ($rows as $r) {
            $deviceCode = (string) ($r['device_code'] ?? '');
            $key = $deviceCode !== ''
                ? $deviceCode
                : (string) $r['ip_hash'] . '|' . (string) $r['ua_hash'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'device_code' => $deviceCode ?: strtoupper(substr((string) $r['ip_hash'], 0, 8)),
                    'ip'          => (string) ($r['ip'] ?? ''),
                    'ip_masked'   => (string) ($r['ip_masked'] ?? ''),
                    'mac'         => (string) ($r['mac'] ?? ''),
                    'mac_source'  => (string) ($r['mac_source'] ?? ''),
                    'ua'          => (string) ($r['ua'] ?? ''),
                    'count'       => 0,
                    'anomalies'   => 0,
                    'last_at'     => '',
                    'classes'     => [],
                    'teachers'    => [],
                    'submissions' => [],
                ];
            }
            $g = &$groups[$key];
            $g['count']++;
            if ((string) $r['submitted_at'] > $g['last_at']) {
                $g['last_at'] = (string) $r['submitted_at'];
            }
            if (!in_array($r['class_name'], $g['classes'], true)) {
                $g['classes'][] = $r['class_name'];
            }
            if (!in_array($r['teacher_name'], $g['teachers'], true)) {
                $g['teachers'][] = $r['teacher_name'];
            }
            if (in_array($r['flag'], ['fast', 'uniform'], true) && empty($r['excluded'])) {
                $g['anomalies']++;
            }
            $g['submissions'][] = [
                'id'           => (int) $r['id'],
                'anon_no'      => self::anonNumber($periodId, (int) $r['id']),
                'teacher_name' => $r['teacher_name'],
                'class_name'   => $r['class_name'],
                'course_name'  => $r['course_name'],
                'avg_score'    => $r['avg_score'],
                'duration_ms'  => (int) $r['duration_ms'],
                'submitted_at' => $r['submitted_at'],
                'flag'         => $r['flag'],
                'reviewed'     => (int) $r['reviewed'],
                'excluded'     => (int) $r['excluded'],
            ];
            unset($g);
        }

        usort($groups, static function ($a, $b) {
            return strcmp((string) $b['last_at'], (string) $a['last_at']);
        });
        return array_slice($groups, 0, $limit);
    }

    /**
     * 单份投票的完整详情：逐题得分、开放题、提交环境、同设备的其他提交
     */
    public static function voteDetail(int $submissionId): ?array
    {
        $s = Db::name('submission')
            ->alias('s')
            ->join('teacher t', 't.id = s.teacher_id')
            ->join('klass k', 'k.id = s.class_id')
            ->join('course c', 'c.id = s.course_id')
            ->join('period p', 'p.id = s.period_id')
            ->where('s.id', $submissionId)
            ->field('s.*, t.name as teacher_name, k.name as class_name, c.name as course_name, p.name as period_name')
            ->find();
        if (!$s) {
            return null;
        }
        $s['anon_no'] = self::anonNumber((int) $s['period_id'], $submissionId);
        $s['device_code'] = (string) ($s['device_code'] ?? '') ?: strtoupper(substr((string) $s['ip_hash'], 0, 8));

        $answers = Db::name('answer')
            ->alias('a')
            ->join('question q', 'q.id = a.question_id')
            ->leftJoin('dimension d', 'd.id = a.dimension_id')
            ->where('a.submission_id', $submissionId)
            ->field('a.*, q.title as question_title, q.type as question_type, q.options as question_options, d.name as dimension_name, d.sort as dimension_sort')
            ->order('dimension_sort', 'asc')
            ->order('q.sort', 'asc')
            ->order('a.id', 'asc')
            ->select()->toArray();
        $ratings = [];
        $texts = [];
        foreach ($answers as $a) {
            if ($a['score'] !== null) {
                $ratings[] = $a;
            } elseif (trim((string) $a['content']) !== '') {
                $texts[] = $a;
            }
        }
        $s['ratings'] = $ratings;
        $s['texts'] = $texts;
        $s['scale_max'] = (int) (Db::name('questionnaire')->where('id', (int) $s['questionnaire_id'])->value('scale_max') ?: 5);

        $q = Db::name('submission')
            ->alias('s')
            ->join('teacher t', 't.id = s.teacher_id')
            ->join('course c', 'c.id = s.course_id')
            ->where('s.period_id', (int) $s['period_id'])
            ->where('s.id', '<>', $submissionId);
        if ((string) $s['device_code'] !== '') {
            $q->where('s.device_code', $s['device_code']);
        } else {
            $q->where('s.ip_hash', $s['ip_hash'])->where('s.ua_hash', $s['ua_hash']);
        }
        $others = $q->field('s.id, s.avg_score, s.submitted_at, s.flag, s.reviewed, s.excluded, t.name as teacher_name, c.name as course_name')
            ->order('s.id', 'desc')->limit(50)->select()->toArray();
        foreach ($others as &$o) {
            $o['anon_no'] = self::anonNumber((int) $s['period_id'], (int) $o['id']);
        }
        unset($o);
        $s['others'] = $others;

        return $s;
    }

    public static function review(int $submissionId, int $adminId, bool $valid, string $note = ''): void
    {
        $s = Db::name('submission')->where('id', $submissionId)->find();
        if (!$s) {
            throw new \RuntimeException('记录不存在');
        }
        Db::name('submission')->where('id', $submissionId)->update([
            'excluded' => $valid ? 0 : 1,
            'reviewed' => 1,
        ]);
        Db::name('audit_log')->insert([
            'admin_id'    => $adminId,
            'action'      => $valid ? 'restore_submission' : 'exclude_submission',
            'target'      => 'submission#' . $submissionId,
            'detail'      => json_encode(['note' => $note, 'flag' => $s['flag'], 'excluded' => $valid ? 0 : 1], JSON_UNESCAPED_UNICODE),
            'ip_hash'     => ev_hash(ev_client_ip()),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ---------------- 导出数据 ---------------- */

    /**
     * 单教员全年题目矩阵：template_row => [month => avg]
     * 用 template_row 作为稳定键，避免跨周期题目 id 变化
     */
    public static function teacherYearMatrix(int $year, int $teacherId, ?int $courseId = null): array
    {
        $q = Db::name('submission')
            ->alias('s')
            ->join('period p', 'p.id = s.period_id')
            ->join('answer a', 'a.submission_id = s.id')
            ->join('question qq', 'qq.id = a.question_id')
            ->where('p.year', $year)
            ->where('s.teacher_id', $teacherId)
            ->whereRaw(self::COUNTED)
            ->whereNotNull('a.score')
            ->whereNotNull('qq.template_row');
        if ($courseId) {
            $q->where('s.course_id', $courseId);
        }
        $rows = $q->field('qq.template_row, p.month, AVG(a.score) as v')
            ->group('qq.template_row, p.month')
            ->select()->toArray();

        $matrix = [];
        foreach ($rows as $r) {
            $matrix[(int) $r['template_row']][(int) $r['month']] = round((float) $r['v'], 2);
        }
        return $matrix;
    }

    /**
     * 全年逐题得分明细：submission_id => [template_row => score]
     */
    public static function yearAnswerMatrix(int $year): array
    {
        $rows = Db::name('submission')
            ->alias('s')
            ->join('period p', 'p.id = s.period_id')
            ->join('answer a', 'a.submission_id = s.id')
            ->join('question qq', 'qq.id = a.question_id')
            ->where('p.year', $year)
            ->whereNotNull('a.score')
            ->whereNotNull('qq.template_row')
            ->field('s.id, qq.template_row, a.score')
            ->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']][(int) $r['template_row']] = (int) $r['score'];
        }
        return $out;
    }

    /**
     * 分数分布：teacher_id => [month => [score => count]]
     */
    public static function distribution(int $year): array
    {
        $rows = Db::name('submission')
            ->alias('s')
            ->join('period p', 'p.id = s.period_id')
            ->join('answer a', 'a.submission_id = s.id')
            ->where('p.year', $year)
            ->whereRaw(self::COUNTED)
            ->whereNotNull('a.score')
            ->field('s.teacher_id, p.month, a.score, COUNT(*) as c')
            ->group('s.teacher_id, p.month, a.score')
            ->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['teacher_id']][(int) $r['month']][(int) $r['score']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * 单教员按月的参评人数与总分
     */
    public static function teacherYearTotals(int $year, int $teacherId, ?int $courseId = null): array
    {
        $q = Db::name('submission')
            ->alias('s')
            ->join('period p', 'p.id = s.period_id')
            ->where('p.year', $year)
            ->where('s.teacher_id', $teacherId)
            ->whereRaw(self::COUNTED);
        if ($courseId) {
            $q->where('s.course_id', $courseId);
        }
        $rows = $q->field('p.month, COUNT(*) as cnt, AVG(s.avg_score) as per_avg, AVG(s.total_score) as total')
            ->group('p.month')
            ->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['month']] = [
                'count'   => (int) $r['cnt'],
                'avg'     => round((float) $r['per_avg'], 2),
                'total'   => round((float) $r['total'], 2),
            ];
        }
        return $out;
    }

    /**
     * 汇总包：全部对象的分维度统计
     */
    public static function packData(int $year): array
    {
        $periods = Db::name('period')->where('year', $year)->order('month', 'asc')->select()->toArray();
        $periodIds = array_column($periods, 'id');

        $targets = Db::name('period_target')
            ->alias('pt')
            ->join('period p', 'p.id = pt.period_id')
            ->join('teacher t', 't.id = pt.teacher_id')
            ->join('klass k', 'k.id = pt.class_id')
            ->join('course c', 'c.id = pt.course_id')
            ->where('p.year', $year)
            ->field('pt.id, pt.period_id, pt.teacher_id, pt.class_id, pt.course_id, pt.state, pt.exclude_reason,
                     p.month, t.name as teacher_name, t.employee_no, k.name as class_name, c.name as course_name')
            ->order('pt.teacher_id asc, p.month asc')
            ->select()->toArray();

        $stats = [];
        if ($periodIds) {
            $rows = Db::name('submission')
                ->alias('s')
                ->whereIn('s.period_id', $periodIds)
                ->whereRaw(self::COUNTED)
                ->field("s.target_id, COUNT(*) as cnt, AVG(s.total_score) as total, AVG(s.avg_score) as avg_score,
                         SUM(CASE WHEN s.flag = 'timeout' THEN 1 ELSE 0 END) as timeout_cnt")
                ->group('s.target_id')
                ->select()->toArray();
            foreach ($rows as $r) {
                $stats[(int) $r['target_id']] = $r;
            }
        }

        $dimRows = [];
        if ($periodIds) {
            $dimRows = Db::name('submission')
                ->alias('s')
                ->whereIn('s.period_id', $periodIds)
                ->whereRaw(self::COUNTED)
                ->join('answer a', 'a.submission_id = s.id')
                ->join('dimension d', 'd.id = a.dimension_id')
                ->whereNotNull('a.score')
                ->field('s.target_id, d.name as dim_name, AVG(a.score) as v')
                ->group('s.target_id, d.name')
                ->select()->toArray();
        }
        $dimMap = [];
        foreach ($dimRows as $d) {
            $dimMap[(int) $d['target_id']][$d['dim_name']] = round((float) $d['v'], 2);
        }

        $dimensions = Db::name('dimension')
            ->whereIn('questionnaire_id', function ($q) use ($periodIds) {
                $q->name('questionnaire')->whereIn('period_id', $periodIds)->field('id');
            })
            ->field('name, MIN(sort) as sort')
            ->group('name')
            ->order('sort', 'asc')
            ->select()->toArray();

        $details = Db::name('submission')
            ->alias('s')
            ->join('period p', 'p.id = s.period_id')
            ->join('teacher t', 't.id = s.teacher_id')
            ->join('klass k', 'k.id = s.class_id')
            ->join('course c', 'c.id = s.course_id')
            ->where('p.year', $year)
            ->field('s.*, p.month, t.name as teacher_name, k.name as class_name, c.name as course_name')
            ->order('s.id', 'asc')
            ->select()->toArray();

        $opinions = Db::name('answer')
            ->alias('a')
            ->join('submission s', 's.id = a.submission_id')
            ->join('period p', 'p.id = s.period_id')
            ->join('teacher t', 't.id = s.teacher_id')
            ->where('p.year', $year)
            ->whereNull('a.score')
            ->where('a.content', '<>', '')
            ->field('a.content, a.question_id, s.teacher_id, t.name as teacher_name, p.month')
            ->order('s.teacher_id asc, p.month asc')
            ->select()->toArray();

        $slots = Db::name('eval_slot')
            ->alias('sl')
            ->join('period p', 'p.id = sl.period_id')
            ->join('klass k', 'k.id = sl.class_id')
            ->where('p.year', $year)
            ->field('sl.*, p.month, k.name as class_name')
            ->order('sl.starts_at', 'asc')
            ->select()->toArray();

        return compact('periods', 'targets', 'stats', 'dimMap', 'dimensions', 'details', 'opinions', 'slots');
    }

    /**
     * 单个周期的配置存档
     */
    public static function archiveInfo(int $periodId): array
    {
        $period = Db::name('period')->where('id', $periodId)->find();
        return [
            'period'        => $period,
            'ip_rules'      => $period['ip_snapshot'] ? json_decode($period['ip_snapshot'], true) : [],
            'roster'        => $period['roster_snapshot'] ? json_decode($period['roster_snapshot'], true) : [],
            'ip_hash'       => $period['ip_snapshot_hash'] ?? '',
            'roster_hash'   => $period['roster_snapshot_hash'] ?? '',
        ];
    }
}
