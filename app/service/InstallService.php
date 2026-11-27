<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 建表与初始化数据（SQLite）
 */
class InstallService
{
    /**
     * 全部建表 / 索引语句
     */
    public static function statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS admin (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'super',
                last_login_at TEXT,
                create_time TEXT
            )",

            "CREATE TABLE IF NOT EXISTS teacher (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_no TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL,
                gender INTEGER NOT NULL DEFAULT 0,
                subject TEXT NOT NULL DEFAULT '',
                dept TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 1,
                create_time TEXT,
                update_time TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_teacher_name ON teacher(name)",

            "CREATE TABLE IF NOT EXISTS klass (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                grade TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 1,
                create_time TEXT,
                update_time TEXT
            )",

            "CREATE TABLE IF NOT EXISTS course (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                code TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 1,
                create_time TEXT
            )",

            "CREATE TABLE IF NOT EXISTS ip_rule (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT NOT NULL DEFAULT 'global',
                period_id INTEGER,
                class_id INTEGER,
                action TEXT NOT NULL DEFAULT 'allow',
                cidr TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                active INTEGER NOT NULL DEFAULT 1,
                create_time TEXT
            )",

            "CREATE TABLE IF NOT EXISTS period (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                year INTEGER NOT NULL,
                month INTEGER NOT NULL,
                name TEXT NOT NULL DEFAULT '',
                starts_at TEXT,
                ends_at TEXT,
                state TEXT NOT NULL DEFAULT 'pending',
                ip_snapshot TEXT,
                ip_snapshot_hash TEXT,
                roster_snapshot TEXT,
                roster_snapshot_hash TEXT,
                archived_at TEXT,
                archived_by INTEGER,
                archive_version INTEGER NOT NULL DEFAULT 0,
                create_time TEXT,
                update_time TEXT,
                UNIQUE (year, month)
            )",

            "CREATE TABLE IF NOT EXISTS period_target (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL,
                course_id INTEGER NOT NULL,
                teacher_id INTEGER NOT NULL,
                state TEXT NOT NULL DEFAULT 'required',
                exclude_reason TEXT NOT NULL DEFAULT '',
                sort INTEGER NOT NULL DEFAULT 0,
                UNIQUE (period_id, class_id, course_id, teacher_id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_pt_period_teacher ON period_target(period_id, teacher_id)",
            "CREATE INDEX IF NOT EXISTS idx_pt_period_class ON period_target(period_id, class_id)",

            "CREATE TABLE IF NOT EXISTS eval_slot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL,
                lab_room TEXT NOT NULL DEFAULT '',
                starts_at TEXT NOT NULL,
                ends_at TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'open',
                create_time TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_slot_pc ON eval_slot(period_id, class_id)",

            "CREATE TABLE IF NOT EXISTS questionnaire (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_id INTEGER NOT NULL UNIQUE,
                title TEXT NOT NULL DEFAULT '',
                scale_max INTEGER NOT NULL DEFAULT 5,
                version TEXT NOT NULL DEFAULT '1.0',
                status INTEGER NOT NULL DEFAULT 1,
                create_time TEXT
            )",

            "CREATE TABLE IF NOT EXISTS dimension (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                questionnaire_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                weight REAL NOT NULL DEFAULT 1,
                sort INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE INDEX IF NOT EXISTS idx_dim_q ON dimension(questionnaire_id)",

            "CREATE TABLE IF NOT EXISTS question (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                questionnaire_id INTEGER NOT NULL,
                dimension_id INTEGER,
                type TEXT NOT NULL DEFAULT 'rating',
                title TEXT NOT NULL,
                options TEXT NOT NULL DEFAULT '',
                weight REAL NOT NULL DEFAULT 1,
                required INTEGER NOT NULL DEFAULT 1,
                sort INTEGER NOT NULL DEFAULT 0,
                template_row INTEGER
            )",
            "CREATE INDEX IF NOT EXISTS idx_q_q ON question(questionnaire_id, sort)",

            "CREATE TABLE IF NOT EXISTS eval_session (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL,
                slot_id INTEGER,
                session_token TEXT NOT NULL UNIQUE,
                ip_hash TEXT NOT NULL DEFAULT '',
                ua_hash TEXT NOT NULL DEFAULT '',
                started_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                submitted_count INTEGER NOT NULL DEFAULT 0,
                state TEXT NOT NULL DEFAULT 'active',
                create_time TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_sess_period ON eval_session(period_id, class_id)",

            "CREATE TABLE IF NOT EXISTS submission (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_id INTEGER NOT NULL,
                target_id INTEGER NOT NULL,
                teacher_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL,
                course_id INTEGER NOT NULL,
                slot_id INTEGER,
                session_id INTEGER NOT NULL,
                questionnaire_id INTEGER NOT NULL,
                ip_hash TEXT NOT NULL DEFAULT '',
                ip_masked TEXT NOT NULL DEFAULT '',
                ua_hash TEXT NOT NULL DEFAULT '',
                ip TEXT NOT NULL DEFAULT '',
                ua TEXT NOT NULL DEFAULT '',
                mac TEXT NOT NULL DEFAULT '',
                mac_source TEXT NOT NULL DEFAULT '',
                device_code TEXT NOT NULL DEFAULT '',
                total_score REAL NOT NULL DEFAULT 0,
                avg_score REAL NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                flag TEXT NOT NULL DEFAULT 'ok',
                reviewed INTEGER NOT NULL DEFAULT 0,
                excluded INTEGER NOT NULL DEFAULT 0,
                submitted_at TEXT NOT NULL,
                UNIQUE (period_id, target_id, ip_hash, ua_hash)
            )",
            "CREATE INDEX IF NOT EXISTS idx_sub_stat ON submission(period_id, teacher_id, flag)",
            "CREATE INDEX IF NOT EXISTS idx_sub_course ON submission(period_id, course_id)",

            "CREATE TABLE IF NOT EXISTS answer (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER NOT NULL,
                question_id INTEGER NOT NULL,
                dimension_id INTEGER,
                score INTEGER,
                content TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_ans_sub ON answer(submission_id)",
            "CREATE INDEX IF NOT EXISTS idx_ans_q ON answer(question_id)",

            "CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL DEFAULT 0,
                action TEXT NOT NULL DEFAULT '',
                target TEXT NOT NULL DEFAULT '',
                detail TEXT,
                ip_hash TEXT NOT NULL DEFAULT '',
                create_time TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_log(create_time)",

            "PRAGMA journal_mode = WAL",
        ];
    }

    /**
     * 老库补列：SQLite 的 CREATE TABLE IF NOT EXISTS 不会修改已存在的表。
     * 列名写死在调用处，不接受外部输入。
     */
    protected static function ensureColumns(string $table, array $columns, array &$log): void
    {
        try {
            $info = Db::query('PRAGMA table_info(' . $table . ')');
            $cols = [];
            foreach ($info as $c) {
                $cols[] = (string) $c['name'];
            }
            if (!$cols) {
                return;
            }
            foreach ($columns as $name => $definition) {
                if (!in_array($name, $cols, true)) {
                    Db::execute('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
                    $log[] = '已为老库补充 ' . $table . '.' . $name . ' 字段';
                }
            }
        } catch (\Throwable $e) {
            // 表刚建好时 PRAGMA 读取失败也属正常，忽略
        }
    }

    public static function run(bool $demo = false): array
    {
        $log = [];
        $dir = dirname((string) config('database.connections.sqlite.database'));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach (self::statements() as $sql) {
            Db::execute($sql);
        }
        $log[] = '表结构已创建';

        // 兼容已有库：CREATE TABLE IF NOT EXISTS 不会给旧表加列，这里逐列补齐
        self::ensureColumns('ip_rule', ['class_id' => 'INTEGER'], $log);
        self::ensureColumns('submission', [
            'ip'          => "TEXT NOT NULL DEFAULT ''",
            'ua'          => "TEXT NOT NULL DEFAULT ''",
            'mac'         => "TEXT NOT NULL DEFAULT ''",
            'mac_source'  => "TEXT NOT NULL DEFAULT ''",
            'device_code' => "TEXT NOT NULL DEFAULT ''",
            'excluded'    => 'INTEGER NOT NULL DEFAULT 0',
        ], $log);

        // 补列完成后再建索引（老库此时才有 device_code 列）
        try {
            Db::execute('CREATE INDEX IF NOT EXISTS idx_sub_device ON submission(period_id, device_code)');
        } catch (\Throwable $e) {
            // 索引已存在或列暂不可用，忽略
        }

        if (!Db::name('admin')->where('username', 'admin')->find()) {
            Db::name('admin')->insert([
                'username'    => 'admin',
                'password'    => password_hash('admin123', PASSWORD_DEFAULT),
                'role'        => 'super',
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            $log[] = '创建管理员 admin / admin123';
        }

        if ($demo) {
            $log = array_merge($log, self::seedDemo());
        }

        return $log;
    }

    protected static function seedDemo(): array
    {
        $log = [];
        $now = date('Y-m-d H:i:s');

        $teachers = [
            ['T001', '张明', 1, 'Java', '教学部'],
            ['T002', '李静', 2, 'UI设计', '教学部'],
            ['T003', '王强', 1, '网络工程', '教学部'],
        ];
        $teacherIds = [];
        foreach ($teachers as $t) {
            $exist = Db::name('teacher')->where('employee_no', $t[0])->find();
            if ($exist) {
                $teacherIds[] = (int) $exist['id'];
                continue;
            }
            $teacherIds[] = (int) Db::name('teacher')->insertGetId([
                'employee_no' => $t[0],
                'name'        => $t[1],
                'gender'      => $t[2],
                'subject'     => $t[3],
                'dept'        => $t[4],
                'status'      => 1,
                'create_time' => $now,
            ]);
        }

        $classIds = [];
        foreach ([['JAVA2601', '2026级'], ['UI2601', '2026级']] as $c) {
            $exist = Db::name('klass')->where('name', $c[0])->find();
            if ($exist) {
                $classIds[] = (int) $exist['id'];
                continue;
            }
            $classIds[] = (int) Db::name('klass')->insertGetId([
                'name'          => $c[0],
                'grade'         => $c[1],
                'status'        => 1,
                'create_time'   => $now,
            ]);
        }

        $courseIds = [];
        foreach ([['Java 基础', 'JAVA'], ['UI 设计', 'UI'], ['网络工程', 'NET']] as $c) {
            $exist = Db::name('course')->where('name', $c[0])->find();
            if ($exist) {
                $courseIds[] = (int) $exist['id'];
                continue;
            }
            $courseIds[] = (int) Db::name('course')->insertGetId([
                'name'        => $c[0],
                'code'        => $c[1],
                'status'      => 1,
                'create_time' => $now,
            ]);
        }

        $pairs = [
            ['teacher_id' => $teacherIds[0], 'class_id' => $classIds[0], 'course_id' => $courseIds[0]],
            ['teacher_id' => $teacherIds[1], 'class_id' => $classIds[0], 'course_id' => $courseIds[1]],
            ['teacher_id' => $teacherIds[2], 'class_id' => $classIds[0], 'course_id' => $courseIds[2]],
            ['teacher_id' => $teacherIds[1], 'class_id' => $classIds[1], 'course_id' => $courseIds[1]],
        ];

        $year = (int) date('Y');
        $month = (int) date('n');
        $period = Db::name('period')->where('year', $year)->where('month', $month)->find();
        if (!$period) {
            $id = (int) Db::name('period')->insertGetId([
                'year'        => $year,
                'month'       => $month,
                'name'        => $year . '年' . $month . '月',
                'starts_at'   => date('Y-m-01 00:00:00'),
                'ends_at'     => date('Y-m-t 23:59:59'),
                'state'       => 'open',
                'create_time' => $now,
            ]);
            $log[] = '创建示例周期 #' . $id;
        } else {
            $id = (int) $period['id'];
        }

        QuestionnaireService::ensureForPeriod($id);
        $added = PeriodService::addTargets($id, $pairs);
        $log[] = '创建示例测评对象 ' . $added . ' 条';

        $slotCount = (int) Db::name('eval_slot')->where('period_id', $id)->count();
        if ($slotCount === 0) {
            $lab = ['1号机房', '2号机房'];
            foreach ($classIds as $i => $classId) {
                // 第一场覆盖当前时间，便于装好后立刻试跑；第二场放到次日
                $start = $i === 0
                    ? date('Y-m-d H:i:s', time() - 600)
                    : date('Y-m-d ', strtotime('+1 day')) . '14:00:00';
                Db::name('eval_slot')->insert([
                    'period_id'      => $id,
                    'class_id'       => $classId,
                    'lab_room'       => $lab[$i % 2],
                    'starts_at'      => $start,
                    'ends_at'        => date('Y-m-d H:i:s', strtotime($start) + 1800),
                    'state'          => 'open',
                    'create_time'    => $now,
                ]);
            }
            $log[] = '创建示例场次';
        }

        // 演示：把本机 IP 绑定到示例班级（创建测评时录入 IP 段），学生打开首页即自动进入
        $bind = Db::name('ip_rule')
            ->where('scope', 'class')
            ->where('period_id', $id)
            ->where('class_id', $classIds[0])
            ->where('cidr', '127.0.0.1/32')
            ->find();
        if (!$bind) {
            Db::name('ip_rule')->insert([
                'scope'       => 'class',
                'period_id'   => $id,
                'class_id'    => $classIds[0],
                'action'      => 'allow',
                'cidr'        => '127.0.0.1/32',
                'note'        => '本机调试：自动进入 JAVA2601',
                'active'      => 1,
                'create_time' => $now,
            ]);
            $log[] = '绑定本机 IP 到示例班级';
        }

        return $log;
    }
}
