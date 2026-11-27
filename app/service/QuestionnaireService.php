<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 问卷结构（按 docx 纸质调查表逐字录入）
 */
class QuestionnaireService
{
    /**
     * 默认问卷结构：5 个维度 20 道量表题 + 2 道开放题
     */
    public static function defaultStructure(): array
    {
        return [
            'title'     => '学员对教员满意度调查表',
            'scale_max' => 5,
            'dimensions' => [
                [
                    'name'      => '课堂管理',
                    'questions' => [
                        ['row' => 3,  'text' => '教员本人没有迟到、早退、接打电话现象。'],
                        ['row' => 4,  'text' => '对迟到、早退、请假、旷课等出勤问题均进行处理。'],
                        ['row' => 5,  'text' => '学员看视频、玩游戏、睡觉、说话、接打手机等违反课堂纪律的问题，能进行及时处理。'],
                        ['row' => 6,  'text' => '学员未完成作业、抄作业等作业问题均进行及时处理。'],
                    ],
                ],
                [
                    'name'      => '内容讲解',
                    'questions' => [
                        ['row' => 7,  'text' => '教员能传达积极的学习心态、鼓励学员认真学习、讲述正确的学习方法。'],
                        ['row' => 8,  'text' => '教员授课过程中熟悉所讲解的课程，上机演示流畅。'],
                        ['row' => 9,  'text' => '授课重点突出，反复总结及回顾。'],
                        ['row' => 10, 'text' => '授课语言清晰、简练、易懂。'],
                        ['row' => 11, 'text' => '声音洪亮，语速合理，声音抑扬顿挫。'],
                        ['row' => 12, 'text' => '能讲解企业实际应用，传达工作经验和项目经验。'],
                    ],
                ],
                [
                    'name'      => '课堂互动',
                    'questions' => [
                        ['row' => 13, 'text' => '教员在授课期间与学员互动、提问、交流比较多。'],
                        ['row' => 14, 'text' => '授课方式生动幽默，能活跃课堂气氛。'],
                        ['row' => 15, 'text' => '授课过程中能表扬和鼓励学员，激发学员积极性。'],
                    ],
                ],
                [
                    'name'      => '耐心辅导',
                    'questions' => [
                        ['row' => 16, 'text' => '教员能有效地解答学员的问题。'],
                        ['row' => 17, 'text' => '教员态度友好、热心，没有不耐烦的情绪。'],
                        ['row' => 18, 'text' => '对学员作业进行细致点评。'],
                        ['row' => 19, 'text' => '练习课能抽出较多时间指导学员。'],
                    ],
                ],
                [
                    'name'      => '授课效果',
                    'questions' => [
                        ['row' => 20, 'text' => '学员能够理解和消化课上的内容。'],
                        ['row' => 21, 'text' => '学员能掌握课上和课下实验的制作思路和制作步骤。'],
                        ['row' => 22, 'text' => '学员能独立完成课上和课下的实验作业。'],
                    ],
                ],
            ],
            'texts' => [
                ['row' => 0, 'text' => '教员的优点'],
                ['row' => 0, 'text' => '对教员的意见和建议'],
            ],
        ];
    }

    /**
     * 为周期创建问卷（已存在则直接返回 id）
     */
    public static function ensureForPeriod(int $periodId): int
    {
        $exist = Db::name('questionnaire')->where('period_id', $periodId)->find();
        if ($exist) {
            return (int) $exist['id'];
        }
        return self::create($periodId, self::defaultStructure());
    }

    /**
     * 按给定结构创建问卷
     */
    public static function create(int $periodId, array $structure): int
    {
        $now = date('Y-m-d H:i:s');
        $qid = (int) Db::name('questionnaire')->insertGetId([
            'period_id'   => $periodId,
            'title'       => $structure['title'] ?? '学员对教员满意度调查表',
            'scale_max'   => (int) ($structure['scale_max'] ?? 5),
            'version'     => '1.0',
            'status'      => 1,
            'create_time' => $now,
        ]);

        $sort = 0;
        foreach ($structure['dimensions'] as $dim) {
            $dimId = (int) Db::name('dimension')->insertGetId([
                'questionnaire_id' => $qid,
                'name'             => $dim['name'],
                'weight'           => 1,
                'sort'             => $sort++,
            ]);
            foreach ($dim['questions'] as $q) {
                Db::name('question')->insert([
                    'questionnaire_id' => $qid,
                    'dimension_id'     => $dimId,
                    'type'             => 'rating',
                    'title'            => $q['text'],
                    'weight'           => 1,
                    'required'         => 1,
                    'sort'             => $sort++,
                    'template_row'     => $q['row'] ?: null,
                ]);
            }
        }
        foreach ($structure['texts'] as $t) {
            Db::name('question')->insert([
                'questionnaire_id' => $qid,
                'dimension_id'     => null,
                'type'             => 'text',
                'title'            => $t['text'],
                'weight'           => 0,
                'required'         => 0,
                'sort'             => $sort++,
                'template_row'     => null,
            ]);
        }

        return $qid;
    }

    /**
     * 取周期问卷完整结构
     */
    public static function structure(int $periodId): ?array
    {
        $q = Db::name('questionnaire')->where('period_id', $periodId)->where('status', 1)->find();
        if (!$q) {
            return null;
        }
        $dims = Db::name('dimension')->where('questionnaire_id', $q['id'])->order('sort', 'asc')->select()->toArray();
        $questions = Db::name('question')->where('questionnaire_id', $q['id'])->order('sort', 'asc')->select()->toArray();

        $grouped = [];
        foreach ($dims as $d) {
            $d['questions'] = [];
            foreach ($questions as $qq) {
                if ((int) $qq['dimension_id'] === (int) $d['id']) {
                    $d['questions'][] = $qq;
                }
            }
            $grouped[] = $d;
        }
        $texts = array_values(array_filter($questions, function ($x) {
            return $x['type'] === 'text';
        }));

        return [
            'questionnaire' => $q,
            'dimensions'    => $grouped,
            'texts'         => $texts,
            'questions'     => $questions,
            'rating_count'  => count(array_filter($questions, function ($x) {
                return $x['type'] === 'rating';
            })),
        ];
    }
}
