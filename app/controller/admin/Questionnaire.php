<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\QuestionnaireService;
use think\facade\Db;

class Questionnaire extends BaseController
{
    public function index($id)
    {
        $id = (int) $id;
        $period = Db::name('period')->where('id', $id)->find();
        if (!$period) {
            return redirect('/admin/periods');
        }
        QuestionnaireService::ensureForPeriod($id);
        $structure = QuestionnaireService::structure($id);

        return view('admin/questionnaire', compact('period', 'structure'));
    }

    public function save($id)
    {
        $id = (int) $id;
        $period = Db::name('period')->where('id', $id)->find();
        if (!$period) {
            return $this->back('/admin/periods', 'err', '周期不存在');
        }
        if ($period['state'] === 'archived') {
            return $this->back('/admin/periods/' . $id . '/questionnaire', 'err', '已归档周期不可修改问卷');
        }

        $structure = QuestionnaireService::structure($id);
        if (!$structure) {
            return $this->back('/admin/periods/' . $id . '/questionnaire', 'err', '问卷不存在');
        }
        $qid = (int) $structure['questionnaire']['id'];
        $now = date('Y-m-d H:i:s');

        // 基本信息
        $scaleMax = (int) $this->request->post('scale_max', 5);
        if ($scaleMax < 2 || $scaleMax > 10) {
            $scaleMax = 5;
        }
        Db::name('questionnaire')->where('id', $qid)->update([
            'title'     => trim((string) $this->request->post('title', $structure['questionnaire']['title'])),
            'scale_max' => $scaleMax,
        ]);

        // 维度改名
        $dims = $this->request->post('dims', []);
        if (is_array($dims)) {
            foreach ($dims as $did => $row) {
                Db::name('dimension')->where('id', (int) $did)->where('questionnaire_id', $qid)->update([
                    'name' => trim((string) ($row['name'] ?? '')),
                ]);
            }
        }

        // 新增维度
        $newDim = trim((string) $this->request->post('new_dimension', ''));
        if ($newDim !== '') {
            Db::name('dimension')->insert([
                'questionnaire_id' => $qid,
                'name'             => $newDim,
                'weight'           => 1,
                'sort'             => 999,
            ]);
        }

        // 题目修改
        $questions = $this->request->post('questions', []);
        if (is_array($questions)) {
            foreach ($questions as $qidRow => $row) {
                Db::name('question')->where('id', (int) $qidRow)->where('questionnaire_id', $qid)->update([
                    'title'    => trim((string) ($row['title'] ?? '')),
                    'required' => isset($row['required']) ? 1 : 0,
                    'weight'   => max(0, (float) ($row['weight'] ?? 1)),
                ]);
            }
        }

        // 新增题目
        $newQuestions = $this->request->post('new_questions', []);
        if (is_array($newQuestions)) {
            foreach ($newQuestions as $did => $title) {
                $title = trim((string) $title);
                if ($title === '') {
                    continue;
                }
                Db::name('question')->insert([
                    'questionnaire_id' => $qid,
                    'dimension_id'     => (int) $did ?: null,
                    'type'             => 'rating',
                    'title'            => $title,
                    'weight'           => 1,
                    'required'         => 1,
                    'sort'             => 999,
                    'template_row'     => null,
                ]);
            }
        }

        // 删除题目
        $delQ = $this->request->post('delete_question', []);
        if (is_array($delQ)) {
            foreach ($delQ as $qidRow => $on) {
                if ($on) {
                    Db::name('question')->where('id', (int) $qidRow)->where('questionnaire_id', $qid)->delete();
                }
            }
        }

        // 删除维度（连同题目）
        $delD = $this->request->post('delete_dimension', []);
        if (is_array($delD)) {
            foreach ($delD as $did => $on) {
                if ($on) {
                    Db::name('question')->where('dimension_id', (int) $did)->where('questionnaire_id', $qid)->delete();
                    Db::name('dimension')->where('id', (int) $did)->where('questionnaire_id', $qid)->delete();
                }
            }
        }

        $this->audit('questionnaire_save', 'questionnaire#' . $qid, ['period' => $id]);

        return $this->back('/admin/periods/' . $id . '/questionnaire', 'ok', '问卷已保存');
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
