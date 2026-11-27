<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * IPv4 网段准入
 */
class IpService
{
    public static function enabled(): bool
    {
        return (bool) env('app.ip_whitelist', true);
    }

    public static function ipToLong(string $ip): ?int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $long = ip2long($ip);
        return $long === false ? null : $long;
    }

    /**
     * 判断 IP 是否落在 CIDR 内
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }
        $parts = explode('/', $cidr);
        $net = self::ipToLong($parts[0]);
        $long = self::ipToLong($ip);
        if ($net === null || $long === null) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : 32;
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
        return ($long & $mask) === ($net & $mask);
    }

    /**
     * 校验 CIDR 写法是否合法
     */
    public static function validCidr(string $cidr): bool
    {
        $parts = explode('/', trim($cidr));
        if (count($parts) > 2) {
            return false;
        }
        if (self::ipToLong($parts[0]) === null) {
            return false;
        }
        if (count($parts) === 2) {
            $bits = $parts[1];
            if (!ctype_digit($bits) || (int) $bits > 32) {
                return false;
            }
        }
        return true;
    }

    protected static function prefixBits(string $cidr): int
    {
        $parts = explode('/', trim($cidr));
        return isset($parts[1]) ? (int) $parts[1] : 32;
    }

    /**
     * 解析用户输入的多个网段（支持换行 / 逗号分隔，允许单个 IP）
     *
     * @return array{valid: array<int,string>, bad: array<int,string>}
     */
    public static function parseCidrs(string $bulk): array
    {
        $valid = [];
        $bad = [];
        $lines = preg_split('/[\r\n,，;；\s]+/', $bulk) ?: [];
        foreach ($lines as $line) {
            $cidr = trim($line);
            if ($cidr === '') {
                continue;
            }
            if (!self::validCidr($cidr)) {
                $bad[] = $cidr;
                continue;
            }
            if (!in_array($cidr, $valid, true)) {
                $valid[] = $cidr;
            }
        }
        return ['valid' => $valid, 'bad' => $bad];
    }

    /**
     * 某周期下各班级配置的 IP 段（创建测评时录入）
     *
     * @return array<int, array<int,string>> class_id => cidr 列表
     */
    public static function classCidrs(int $periodId): array
    {
        $rows = Db::name('ip_rule')
            ->where('active', 1)
            ->where('scope', 'class')
            ->where('action', 'allow')
            ->where('period_id', $periodId)
            ->order('id', 'asc')
            ->field('class_id,cidr')
            ->select()->toArray();

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['class_id'];
            $out[$cid][] = (string) $r['cidr'];
        }
        return $out;
    }

    /**
     * 覆盖保存某周期某班级的 IP 段（用于创建测评与名单页维护）
     *
     * @param array<int,string> $cidrs 已校验的网段
     */
    public static function setClassCidrs(int $periodId, int $classId, array $cidrs, string $note = ''): int
    {
        Db::name('ip_rule')
            ->where('scope', 'class')
            ->where('period_id', $periodId)
            ->where('class_id', $classId)
            ->delete();

        $now = date('Y-m-d H:i:s');
        $saved = 0;
        foreach ($cidrs as $cidr) {
            Db::name('ip_rule')->insert([
                'scope'       => 'class',
                'period_id'   => $periodId,
                'class_id'    => $classId,
                'action'      => 'allow',
                'cidr'        => (string) $cidr,
                'note'        => $note,
                'active'      => 1,
                'create_time' => $now,
            ]);
            $saved++;
        }
        return $saved;
    }

    /**
     * 该 IP 在当前周期可参与的班级集合（首页展示「我的调研」用）
     *
     * 规则：
     * - 没有任何班级级规则时，视为全部班级可用（沿用「无规则默认放行」）；
     * - 有班级级规则时，只返回本 IP 命中 allow 的班级，并排除命中 deny 的班级；
     * - 一个网段可以绑定多个班级（例如整层机房），学生会在首页看到多个班级的调研。
     *
     * @param array<int,int|string> $classIds 候选班级
     * @return array<int,int>
     */
    public static function allowedClasses(string $ip, int $periodId, array $classIds): array
    {
        if ($ip === '0.0.0.0' || $ip === '::1') {
            $ip = '127.0.0.1';
        }

        $allowed = [];
        $denied = [];
        $hasRules = false;

        $rows = Db::name('ip_rule')
            ->where('active', 1)
            ->where('scope', 'class')
            ->order('id', 'asc')
            ->select()->toArray();
        foreach ($rows as $r) {
            $rid = $r['period_id'] ?? null;
            if ($rid !== null && $rid !== '' && (int) $rid !== $periodId) {
                continue;
            }
            $hasRules = true;
            if (!self::inCidr($ip, (string) $r['cidr'])) {
                continue;
            }
            $cid = (int) $r['class_id'];
            if ((string) $r['action'] === 'deny') {
                $denied[$cid] = true;
                continue;
            }
            $bits = self::prefixBits((string) $r['cidr']);
            if (!isset($allowed[$cid]) || $bits > $allowed[$cid]) {
                $allowed[$cid] = $bits;
            }
        }

        $candidates = array_map('intval', $classIds);

        if (!$hasRules) {
            return array_values(array_unique($candidates));
        }

        $out = [];
        foreach ($candidates as $cid) {
            if (isset($denied[$cid])) {
                continue;
            }
            if (isset($allowed[$cid])) {
                $out[] = $cid;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 周期 + 班级生效的规则集合：全局 + 周期级 + 班级级
     * class_id 不为 null 时，同时纳入「该班级」在「该周期」下的班级级规则
     */
    public static function rulesForPeriod(?int $periodId, ?int $classId = null): array
    {
        $rows = Db::name('ip_rule')->where('active', 1)->order('id', 'asc')->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $scope = (string) ($r['scope'] ?? 'global');
            if ($scope === 'period') {
                if ($periodId === null || (int) ($r['period_id'] ?? -1) !== (int) $periodId) {
                    continue;
                }
            }
            if ($scope === 'class') {
                if ($classId === null || (int) ($r['class_id'] ?? -1) !== (int) $classId) {
                    continue;
                }
                // 班级级规则：period_id 为空表示「所有周期」都适用该班级
                $rid = $r['period_id'];
                if ($rid !== null && $rid !== '') {
                    if ($periodId === null || (int) $rid !== (int) $periodId) {
                        continue;
                    }
                }
            }
            if ($scope !== 'global' && $scope !== 'period' && $scope !== 'class') {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 命中的规则（用于 explain / allowed）
     */
    protected static function matching(string $ip, ?int $periodId, ?int $classId = null): array
    {
        $hits = [];
        foreach (self::rulesForPeriod($periodId, $classId) as $r) {
            if (!self::inCidr($ip, (string) $r['cidr'])) {
                continue;
            }
            $hits[] = $r;
        }
        return $hits;
    }

    /**
     * 是否放行：deny 优先，其次 allow；未配置任何规则时放行
     */
    public static function allowed(string $ip, ?int $periodId, ?int $classId = null): bool
    {
        if (!self::enabled()) {
            return true;
        }
        if ($ip === '0.0.0.0' || $ip === '::1') {
            // 本机调试（php think / 内网直连）
            $ip = '127.0.0.1';
        }
        $hits = self::matching($ip, $periodId, $classId);
        if (!$hits) {
            return true;
        }
        $allowed = false;
        foreach ($hits as $r) {
            if ((string) $r['action'] === 'deny') {
                return false;
            }
            $allowed = true;
        }
        return $allowed;
    }

    /**
     * 调试用：返回命中的规则
     */
    public static function explain(string $ip, ?int $periodId, ?int $classId = null): array
    {
        if ($ip === '::1' || $ip === '0.0.0.0') {
            $ip = '127.0.0.1';
        }
        $hits = self::matching($ip, $periodId, $classId);
        return [
            'ip'      => $ip,
            'hits'    => $hits,
            'allowed' => self::allowed($ip, $periodId, $classId),
            'enabled' => self::enabled(),
        ];
    }
}
