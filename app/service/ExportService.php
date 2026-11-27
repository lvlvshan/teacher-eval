<?php
declare(strict_types=1);

namespace app\service;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\facade\Db;

/**
 * Excel 导出：单教员年表（对齐现有模板）+ 汇总包
 */
class ExportService
{
    protected const TPL_FIRST_ROW = 3;

    protected static function exportDir(): string
    {
        $dir = runtime_path() . 'export' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    protected static function safe(string $name): string
    {
        return preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $name);
    }

    /* ==================== 单教员年表 ==================== */

    public static function annualName(int $year, int $teacherId, ?int $courseId = null): string
    {
        $t = Db::name('teacher')->where('id', $teacherId)->find();
        $name = $t ? $t['name'] : ('教师' . $teacherId);
        if ($courseId) {
            $c = Db::name('course')->where('id', $courseId)->find();
            if ($c) {
                $name .= '-' . $c['name'];
            }
        }
        return self::safe("学员满意度得分-{$name}-{$year}年") . '.xlsx';
    }

    /**
     * 生成单个教员的年表，返回文件路径
     */
    public static function annualPath(int $year, int $teacherId, ?int $courseId = null): string
    {
        $teacher = Db::name('teacher')->where('id', $teacherId)->find();
        if (!$teacher) {
            throw new \RuntimeException('教员不存在');
        }

        [$dims, $questions] = self::templateQuestions($year);
        $matrix = ReportService::teacherYearMatrix($year, $teacherId, $courseId);
        $totals = ReportService::teacherYearTotals($year, $teacherId, $courseId);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::safe(mb_substr($teacher['name'], 0, 25)));

        // 标题
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', '学员满意度得分');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // 表头
        $sheet->setCellValue('A2', '分类');
        $sheet->setCellValue('B2', '事件');
        for ($m = 1; $m <= 12; $m++) {
            $sheet->setCellValueByColumnAndRow(2 + $m, 2, $m . '月');
        }
        self::styleHeader($sheet, 'A2:N2');

        // 题目行
        $row = self::TPL_FIRST_ROW;
        $dimStart = [];
        $dimEnd = [];
        $dimNames = [];
        foreach ($questions as $q) {
            $dimName = $q['dimension_name'] ?: '其他';
            if (!isset($dimStart[$dimName])) {
                $dimStart[$dimName] = $row;
                $dimNames[] = $dimName;
            }
            $dimEnd[$dimName] = $row;

            $sheet->setCellValueExplicit('A' . $row, '', DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, $q['title']);
            $tplRow = (int) $q['template_row'];
            for ($m = 1; $m <= 12; $m++) {
                $v = $matrix[$tplRow][$m] ?? null;
                if ($v !== null) {
                    $sheet->setCellValueByColumnAndRow(2 + $m, $row, (float) $v);
                }
            }
            $row++;
        }

        // 维度名称合并
        foreach ($dimNames as $dn) {
            $s = $dimStart[$dn];
            $e = $dimEnd[$dn];
            if ($e > $s) {
                $sheet->mergeCells("A{$s}:A{$e}");
            }
            $sheet->setCellValue('A' . $s, $dn);
            $sheet->getStyle("A{$s}:A{$e}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
        }

        // 总分
        $totalRow = $row;
        $sheet->setCellValue('A' . $totalRow, '总分');
        $sheet->mergeCells("A{$totalRow}:B{$totalRow}");
        $sheet->getStyle('A' . $totalRow)->getFont()->setBold(true);
        for ($m = 1; $m <= 12; $m++) {
            $v = $totals[$m]['total'] ?? null;
            if ($v !== null) {
                $sheet->setCellValueByColumnAndRow(2 + $m, $totalRow, (float) $v);
            }
        }

        // 参评人数
        $countRow = $totalRow + 1;
        $sheet->setCellValue('A' . $countRow, '参评人数');
        $sheet->mergeCells("A{$countRow}:B{$countRow}");
        for ($m = 1; $m <= 12; $m++) {
            $v = $totals[$m]['count'] ?? null;
            if ($v !== null) {
                $sheet->setCellValueByColumnAndRow(2 + $m, $countRow, (int) $v);
            }
        }

        $lastRow = $countRow;
        $sheet->getStyle("A3:N{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A" . self::TPL_FIRST_ROW . ":N{$totalRow}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("C{$countRow}:N{$countRow}")->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle("C{$totalRow}:N{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("B" . self::TPL_FIRST_ROW . ":B{$lastRow}")->getAlignment()->setWrapText(true);

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(72);
        for ($m = 1; $m <= 12; $m++) {
            $sheet->getColumnDimensionByColumn(2 + $m)->setWidth(8);
        }
        $sheet->freezePane('C3');

        $path = self::exportDir() . 'annual_' . $year . '_' . $teacherId . ($courseId ? '_' . $courseId : '') . '_' . uniqid() . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /**
     * 全部教员打包为 zip
     */
    public static function annualAllZip(int $year): string
    {
        $teachers = Db::name('teacher')->where('status', 1)->order('name asc')->select()->toArray();
        if (!$teachers) {
            throw new \RuntimeException('没有可导出的教员');
        }

        $tmpDir = self::exportDir() . 'pack_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($tmpDir, 0777, true);

        $files = [];
        foreach ($teachers as $t) {
            try {
                $p = self::annualPath($year, (int) $t['id'], null);
                $target = $tmpDir . self::annualName($year, (int) $t['id'], null);
                rename($p, $target);
                $files[] = $target;
            } catch (\Throwable $e) {
                continue;
            }
        }
        if (!$files) {
            throw new \RuntimeException('没有任何教员在本年度有可导出的数据');
        }

        $zipPath = self::exportDir() . 'annual_all_' . $year . '_' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建压缩包');
        }
        foreach ($files as $f) {
            $zip->addFile($f, basename($f));
        }
        $zip->close();

        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        return $zipPath;
    }

    /**
     * 从当年任意周期取问卷题目模板
     */
    protected static function templateQuestions(int $year): array
    {
        $qid = Db::name('questionnaire')->alias('q')
            ->join('period p', 'p.id = q.period_id')
            ->where('p.year', $year)
            ->order('p.month', 'desc')
            ->value('q.id');
        if (!$qid) {
            $qid = Db::name('questionnaire')->order('id', 'desc')->value('id');
        }
        if (!$qid) {
            throw new \RuntimeException('还没有问卷，无法导出');
        }

        $dims = Db::name('dimension')->where('questionnaire_id', $qid)->order('sort', 'asc')->select()->toArray();
        $dimName = [];
        foreach ($dims as $d) {
            $dimName[(int) $d['id']] = $d['name'];
        }

        $rows = Db::name('question')->where('questionnaire_id', $qid)
            ->where('type', 'rating')
            ->whereNotNull('template_row')
            ->order('sort', 'asc')
            ->select()->toArray();

        $questions = [];
        foreach ($rows as $r) {
            $questions[] = [
                'title'          => $r['title'],
                'template_row'   => (int) $r['template_row'],
                'dimension_name' => $dimName[(int) $r['dimension_id']] ?? '',
            ];
        }
        usort($questions, function ($a, $b) {
            return $a['template_row'] <=> $b['template_row'];
        });

        return [$dims, $questions];
    }

    /* ==================== 汇总包 ==================== */

    public static function packPath(int $year, bool $withArchive = false): string
    {
        $pack = ReportService::packData($year);
        $distribution = ReportService::distribution($year);
        $answerMatrix = ReportService::yearAnswerMatrix($year);
        [, $questions] = self::templateQuestions($year);

        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $dimNames = array_map(function ($d) {
            return $d['name'];
        }, $pack['dimensions']);

        /* ---------- 1. 汇总 ---------- */
        $sheet = $book->createSheet();
        $sheet->setTitle('汇总');
        $headers = array_merge(
            ['月份', '教员', '工号', '班级', '课程', '参评人数'],
            $dimNames,
            ['总均分', '排名']
        );
        $rows = [];
        // 先算每月排名
        $byMonth = [];
        foreach ($pack['targets'] as $t) {
            $s = $pack['stats'][(int) $t['id']] ?? null;
            if (!$s) {
                continue;
            }
            $byMonth[(int) $t['month']][] = ['tid' => (int) $t['id'], 'total' => (float) $s['total']];
        }
        $rankMap = [];
        foreach ($byMonth as $month => $arr) {
            usort($arr, function ($a, $b) {
                return $b['total'] <=> $a['total'];
            });
            $i = 1;
            foreach ($arr as $x) {
                $rankMap[$x['tid']] = $i++;
            }
        }

        foreach ($pack['targets'] as $t) {
            $s = $pack['stats'][(int) $t['id']] ?? null;
            $cnt = $s ? (int) $s['cnt'] : 0;
            $line = [
                (int) $t['month'] . '月',
                $t['teacher_name'],
                $t['employee_no'],
                $t['class_name'],
                $t['course_name'],
                $cnt,
            ];
            foreach ($dimNames as $dn) {
                $line[] = isset($pack['dimMap'][(int) $t['id']][$dn]) ? $pack['dimMap'][(int) $t['id']][$dn] : null;
            }
            $line[] = $s ? round((float) $s['total'], 2) : null;
            $rank = $rankMap[(int) $t['id']] ?? null;
            $line[] = $rank;
            $rows[] = $line;
        }
        self::fillSheet($sheet, '月度汇总（' . $year . '年）', $headers, $rows, [7, 12, 12, 14, 16, 10]);
        $sheet->freezePane('C3');

        /* ---------- 2. 明细 ---------- */
        $sheet = $book->createSheet();
        $sheet->setTitle('明细');
        $qHeads = [];
        foreach ($questions as $q) {
            $qHeads[] = 'Q' . ((int) $q['template_row'] - 2);
        }
        $headers = array_merge(['匿名编号', '月份', '教员', '班级', '课程'], $qHeads, ['总均分', '耗时(秒)', '提交时间', '状态', '网段']);
        $rows = [];
        $i = 1;
        foreach ($pack['details'] as $d) {
            $line = [
                'S' . str_pad((string) $i++, 4, '0', STR_PAD_LEFT),
                (int) $d['month'] . '月',
                $d['teacher_name'],
                $d['class_name'],
                (int) $d['month'] . '月/' . $d['course_name'],
            ];
            foreach ($questions as $q) {
                $line[] = $answerMatrix[(int) $d['id']][(int) $q['template_row']] ?? null;
            }
            $line[] = round((float) $d['avg_score'], 2);
            $line[] = round((int) $d['duration_ms'] / 1000, 0);
            $line[] = $d['submitted_at'];
            $tags = [];
            if (!empty($d['excluded'])) {
                $tags[] = '已剔除';
            }
            if (!empty($d['reviewed'])) {
                $tags[] = '已复核';
            }
            $line[] = ev_flag_text($d['flag']) . ($tags ? '（' . implode('，', $tags) . '）' : '');
            $line[] = $d['ip_masked'];
            $rows[] = $line;
        }
        self::fillSheet($sheet, '提交明细（匿名）', $headers, $rows, [10, 7, 10, 14, 18]);
        $sheet->freezePane('C3');

        /* ---------- 3. 分布 ---------- */
        $sheet = $book->createSheet();
        $sheet->setTitle('分布');
        $headers = ['月份', '教员', '5分', '4分', '3分', '2分', '1分', '合计'];
        $rows = [];
        $teacherName = [];
        foreach ($pack['targets'] as $t) {
            $teacherName[(int) $t['teacher_id']] = $t['teacher_name'];
        }
        foreach ($distribution as $tid => $months) {
            foreach ($months as $month => $scores) {
                $line = [$month . '月', $teacherName[$tid] ?? ('#' . $tid)];
                $sum = 0;
                for ($s = 5; $s >= 1; $s--) {
                    $c = $scores[$s] ?? 0;
                    $line[] = $c;
                    $sum += $c;
                }
                $line[] = $sum;
                $rows[] = $line;
            }
        }
        self::fillSheet($sheet, '分数分布', $headers, $rows, [7, 12]);

        /* ---------- 4. 趋势 ---------- */
        $sheet = $book->createSheet();
        $sheet->setTitle('趋势');
        $headers = array_merge(['教员', '课程'], array_map(function ($m) {
            return $m . '月';
        }, range(1, 12)), ['年度均分']);
        $trend = [];
        foreach ($pack['targets'] as $t) {
            $key = $t['teacher_id'] . '|' . $t['course_id'];
            if (!isset($trend[$key])) {
                $trend[$key] = [
                    'teacher' => $t['teacher_name'],
                    'course'  => $t['course_name'],
                    'months'  => [],
                ];
            }
            $s = $pack['stats'][(int) $t['id']] ?? null;
            if ($s) {
                $trend[$key]['months'][(int) $t['month']] = round((float) $s['total'], 2);
            }
        }
        $rows = [];
        foreach ($trend as $tr) {
            $line = [$tr['teacher'], $tr['course']];
            $vals = [];
            for ($m = 1; $m <= 12; $m++) {
                $v = $tr['months'][$m] ?? null;
                $line[] = $v;
                if ($v !== null) {
                    $vals[] = $v;
                }
            }
            $line[] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
            $rows[] = $line;
        }
        self::fillSheet($sheet, '总分月度趋势（满分 100）', $headers, $rows, [12, 16]);

        /* ---------- 5. 场次 ---------- */
        $sheet = $book->createSheet();
        $sheet->setTitle('场次');
        $headers = ['月份', '班级', '机房', '开始', '结束', '实交'];
        $slotCount = [];
        foreach ($pack['details'] as $d) {
            if (!empty($d['slot_id'])) {
                $slotCount[(int) $d['slot_id']] = ($slotCount[(int) $d['slot_id']] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($pack['slots'] as $sl) {
            $actual = $slotCount[(int) $sl['id']] ?? 0;
            $rows[] = [
                (int) $sl['month'] . '月',
                $sl['class_name'],
                $sl['lab_room'],
                $sl['starts_at'],
                $sl['ends_at'],
                $actual,
            ];
        }
        self::fillSheet($sheet, '机房场次完成情况', $headers, $rows, [7, 14, 12, 20, 20]);

        /* ---------- 6. 优点与建议 ---------- */
        $sheet = $book->createSheet();
        $sheet->setTitle('优点与建议');
        $headers = ['月份', '教员', '题目', '内容'];
        $qTitle = [];
        foreach ($questions as $q) {
            $qTitle[$q['template_row']] = $q['title'];
        }
        $textTitles = Db::name('question')->where('type', 'text')->order('sort', 'asc')->select()->toArray();
        $textMap = [];
        foreach ($textTitles as $tt) {
            $textMap[(int) $tt['id']] = $tt['title'];
        }
        $rows = [];
        foreach ($pack['opinions'] as $o) {
            $rows[] = [
                (int) $o['month'] . '月',
                $o['teacher_name'],
                $textMap[(int) $o['question_id']] ?? '文字建议',
                $o['content'],
            ];
        }
        self::fillSheet($sheet, '教员的优点与建议（原文）', $headers, $rows, [7, 12, 18, 80]);
        $sheet->getStyle('D3:D' . max(3, count($rows) + 2))->getAlignment()->setWrapText(true);

        /* ---------- 7. 配置存档 ---------- */
        if ($withArchive) {
            $sheet = $book->createSheet();
            $sheet->setTitle('配置存档');
            $rows = [];
            foreach ($pack['periods'] as $p) {
                $rows[] = [
                    $p['name'],
                    ev_state_text($p['state']),
                    $p['archived_at'] ?: '未归档',
                    'v' . (int) $p['archive_version'],
                    $p['ip_snapshot_hash'] ? substr($p['ip_snapshot_hash'], 0, 16) : '-',
                    $p['roster_snapshot_hash'] ? substr($p['roster_snapshot_hash'], 0, 16) : '-',
                ];
            }
            self::fillSheet($sheet, '周期归档与快照核验', ['周期', '状态', '归档时间', '版本', 'IP规则哈希', '名单哈希'], $rows, [14, 10, 20, 8, 20, 20]);
        }

        $path = self::exportDir() . 'pack_' . $year . '_' . uniqid() . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /* ==================== 样式工具 ==================== */

    protected static function styleHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFEDF2F7');
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    protected static function fillSheet($sheet, string $title, array $headers, array $rows, array $widths): void
    {
        $cols = count($headers);
        $lastCol = self::colLetter($cols);

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);

        foreach ($headers as $i => $h) {
            $sheet->setCellValueByColumnAndRow($i + 1, 2, $h);
        }
        self::styleHeader($sheet, "A2:{$lastCol}2");
        $sheet->getStyle("A2:{$lastCol}2")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(2)->setRowHeight(22);

        $r = 3;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    $sheet->setCellValueByColumnAndRow($i + 1, $r, $v);
                } else {
                    $sheet->setCellValueExplicitByColumnAndRow($i + 1, $r, (string) $v, DataType::TYPE_STRING);
                }
            }
            $r++;
        }

        $lastRow = max(3, $r - 1);
        $sheet->getStyle("A3:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A3:{$lastCol}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach ($widths as $i => $w) {
            $sheet->getColumnDimension(self::colLetter($i + 1))->setWidth($w);
        }
        for ($i = count($widths); $i < $cols; $i++) {
            $sheet->getColumnDimension(self::colLetter($i + 1))->setWidth(9);
        }
    }

    protected static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }
}
