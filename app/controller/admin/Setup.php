<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;

/**
 * 基础数据：教员 / 班级 / 课程
 */
class Setup extends BaseController
{
    /* ================= 教员 ================= */

    public function teachers()
    {
        $kw = trim((string) $this->request->get('kw', ''));
        $q = Db::name('teacher');
        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('name', '%' . $kw . '%')->whereOr('employee_no', 'like', '%' . $kw . '%');
            });
        }
        $list = $q->order('id', 'desc')->select()->toArray();

        $edit = null;
        $eid = (int) $this->request->get('edit', 0);
        if ($eid) {
            $edit = Db::name('teacher')->where('id', $eid)->find();
        }

        return view('admin/teachers', compact('list', 'kw', 'edit'));
    }

    public function saveTeacher()
    {
        $id = (int) $this->request->post('id', 0);
        $data = [
            'employee_no' => trim((string) $this->request->post('employee_no', '')),
            'name'        => trim((string) $this->request->post('name', '')),
            'gender'      => (int) $this->request->post('gender', 0),
            'subject'     => trim((string) $this->request->post('subject', '')),
            'dept'        => trim((string) $this->request->post('dept', '')),
            'status'      => (int) $this->request->post('status', 1),
            'update_time' => date('Y-m-d H:i:s'),
        ];
        if ($data['name'] === '') {
            return $this->back('/admin/teachers', 'err', '姓名不能为空');
        }
        if ($id > 0) {
            Db::name('teacher')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::name('teacher')->insert($data);
        }
        return $this->back('/admin/teachers', 'ok', '已保存');
    }

    public function deleteTeacher()
    {
        $id = (int) $this->request->post('id', 0);
        Db::name('teacher')->where('id', $id)->delete();
        return $this->back('/admin/teachers', 'ok', '已删除');
    }

    public function importTeachers()
    {
        $text = (string) $this->request->post('csv', '');
        $file = $this->request->file('file');
        if ($file) {
            $text = (string) file_get_contents($file->getRealPath());
        }
        if (trim($text) === '') {
            return $this->back('/admin/teachers', 'err', '没有解析到内容');
        }
        $rows = ev_csv_parse($text);
        $n = 0;
        $skip = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $i => $r) {
            if ($i === 0 && (mb_strpos((string) $r[0], '工号') !== false || mb_strpos((string) $r[1], '姓名') !== false)) {
                continue;
            }
            $no = trim((string) ($r[0] ?? ''));
            $name = trim((string) ($r[1] ?? ''));
            if ($name === '') {
                $skip++;
                continue;
            }
            $gender = 0;
            $g = (string) ($r[2] ?? '');
            if ($g === '男' || $g === '1') {
                $gender = 1;
            } elseif ($g === '女' || $g === '2') {
                $gender = 2;
            }
            $exist = $no !== '' ? Db::name('teacher')->where('employee_no', $no)->find() : null;
            if ($exist) {
                Db::name('teacher')->where('id', $exist['id'])->update([
                    'name' => $name, 'gender' => $gender,
                    'subject' => trim((string) ($r[3] ?? '')),
                    'dept' => trim((string) ($r[4] ?? '')),
                    'update_time' => $now,
                ]);
            } else {
                Db::name('teacher')->insert([
                    'employee_no' => $no, 'name' => $name, 'gender' => $gender,
                    'subject' => trim((string) ($r[3] ?? '')),
                    'dept' => trim((string) ($r[4] ?? '')),
                    'status' => 1, 'create_time' => $now,
                ]);
            }
            $n++;
        }
        return $this->back('/admin/teachers', 'ok', "导入完成：{$n} 条，跳过 {$skip} 条");
    }

    /* ================= 班级 ================= */

    public function classes()
    {
        $list = Db::name('klass')->order('id', 'desc')->select()->toArray();
        $edit = null;
        $eid = (int) $this->request->get('edit', 0);
        if ($eid) {
            $edit = Db::name('klass')->where('id', $eid)->find();
        }
        return view('admin/classes', compact('list', 'edit'));
    }

    public function saveClass()
    {
        $id = (int) $this->request->post('id', 0);
        $data = [
            'name'          => trim((string) $this->request->post('name', '')),
            'grade'         => trim((string) $this->request->post('grade', '')),
            'status'        => (int) $this->request->post('status', 1),
            'update_time'   => date('Y-m-d H:i:s'),
        ];
        if ($data['name'] === '') {
            return $this->back('/admin/classes', 'err', '班级名称不能为空');
        }
        if ($id > 0) {
            Db::name('klass')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::name('klass')->insert($data);
        }
        return $this->back('/admin/classes', 'ok', '已保存');
    }

    public function deleteClass()
    {
        $id = (int) $this->request->post('id', 0);
        Db::name('klass')->where('id', $id)->delete();
        return $this->back('/admin/classes', 'ok', '已删除');
    }

    /* ================= 课程 ================= */

    public function courses()
    {
        $list = Db::name('course')->order('id', 'desc')->select()->toArray();
        $edit = null;
        $eid = (int) $this->request->get('edit', 0);
        if ($eid) {
            $edit = Db::name('course')->where('id', $eid)->find();
        }
        return view('admin/courses', compact('list', 'edit'));
    }

    public function saveCourse()
    {
        $id = (int) $this->request->post('id', 0);
        $data = [
            'name'   => trim((string) $this->request->post('name', '')),
            'code'   => trim((string) $this->request->post('code', '')),
            'status' => (int) $this->request->post('status', 1),
        ];
        if ($data['name'] === '') {
            return $this->back('/admin/courses', 'err', '课程名称不能为空');
        }
        if ($id > 0) {
            Db::name('course')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::name('course')->insert($data);
        }
        return $this->back('/admin/courses', 'ok', '已保存');
    }

    public function deleteCourse()
    {
        $id = (int) $this->request->post('id', 0);
        Db::name('course')->where('id', $id)->delete();
        return $this->back('/admin/courses', 'ok', '已删除');
    }

    /* ================= 公共 ================= */

    protected function back(string $url, string $type, string $msg)
    {
        session('flash', ['type' => $type, 'msg' => $msg]);
        return redirect($url);
    }
}
