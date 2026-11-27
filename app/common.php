<?php
// 应用公共文件

if (!function_exists('ev_secret')) {
    /**
     * 用于 HMAC 的服务端密钥
     */
    function ev_secret(): string
    {
        $secret = env('eval.secret', '');
        return $secret ?: 'please-change-this-eval-secret';
    }
}

if (!function_exists('ev_hash')) {
    /**
     * 不可逆哈希（IP / UA / 设备标识）
     */
    function ev_hash(string $value): string
    {
        return hash_hmac('sha256', $value, ev_secret());
    }
}

if (!function_exists('ev_mask_ip')) {
    /**
     * 掩码到 /24，便于审计但不泄露具体主机
     */
    function ev_mask_ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        return 'ipv6';
    }
}

if (!function_exists('ev_client_ip')) {
    /**
     * 取客户端真实 IP。
     * 默认只信任 REMOTE_ADDR；若前置 Nginx 已用 real_ip 模块改写，
     * 这里拿到的就是真实地址。绝不再解析客户端可伪造的 X-Forwarded-For。
     */
    function ev_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        if (strpos($ip, '::ffff:') === 0) {
            $ip = substr($ip, 7);
        }
        return $ip;
    }
}

if (!function_exists('ev_client_mac')) {
    /**
     * 在服务端从 ARP/邻居表按 IP 查客户端 MAC。
     * 浏览器本身无法读取 MAC；仅当服务器与客户端处于同一二层网段时可查到，
     * 跨网段、代理或 NAT 时通常为空。
     *
     * @return array{mac:string,source:string}
     */
    function ev_client_mac(string $ip): array
    {
        $empty = ['mac' => '', 'source' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $empty;
        }

        $normalize = static function (string $mac): string {
            $mac = strtolower(str_replace('-', ':', trim($mac)));
            if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                return '';
            }
            if ($mac === '00:00:00:00:00:00' || $mac === 'ff:ff:ff:ff:ff:ff') {
                return '';
            }
            return $mac;
        };

        // Linux 宿主机 ARP 表（容器使用 host 网络时即为宿主机邻居表）
        $arpFile = '/proc/net/arp';
        if (is_readable($arpFile)) {
            $lines = @file($arpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            array_shift($lines);
            foreach ($lines as $line) {
                $f = preg_split('/\s+/', trim($line));
                if (count($f) < 4 || $f[0] !== $ip) {
                    continue;
                }
                if ((hexdec($f[2]) & 0x2) === 0) {
                    continue;
                }
                $mac = $normalize($f[3]);
                if ($mac) {
                    return ['mac' => $mac, 'source' => 'arp'];
                }
            }
        }

        // 兜底：ip neigh（仅在 Linux 且函数可用时；Windows 下跳过无谓的 shell 调用）
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
            $out = @shell_exec('ip neigh show ' . escapeshellarg($ip) . ' 2>/dev/null');
            if (is_string($out) && preg_match('/\blladdr\s+([0-9a-f:.-]{17})/i', $out, $m)) {
                $mac = $normalize($m[1]);
                if ($mac) {
                    return ['mac' => $mac, 'source' => 'arp'];
                }
            }
        }

        return $empty;
    }
}

if (!function_exists('ev_flag_text')) {
    function ev_flag_text(string $flag): string
    {
        return [
            'ok'      => '正常',
            'fast'    => '过快',
            'uniform' => '同分',
            'timeout' => '超时提交',
        ][$flag] ?? $flag;
    }
}

if (!function_exists('ev_state_text')) {
    function ev_state_text(string $state): string
    {
        return [
            'pending'  => '未开始',
            'open'     => '进行中',
            'closed'   => '已截止',
            'archived' => '已归档',
        ][$state] ?? $state;
    }
}

if (!function_exists('ev_csv_parse')) {
    /**
     * 解析 CSV 文本为二维数组（支持 GBK/UTF-8 自动识别）
     */
    function ev_csv_parse(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'GBK');
        }
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $rows = [];
        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map('trim', str_getcsv($line));
        }
        return $rows;
    }
}
