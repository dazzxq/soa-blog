<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\DomainError;
use App\Json;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Owner-scoped CRUD for experience / education / skills.
 *
 * Every write is scoped strictly by the gateway-trusted X-User-Id header (D-11, T-02-01):
 * we NEVER read user_id from the request body. On a 0-rowcount update/delete we return a
 * UNIFORM 404 (never 403) so "not found" and "not owned" are indistinguishable — this closes
 * the row-existence oracle. No second SELECT is issued to disambiguate.
 */
final class ProfileController
{
    /** Resolve and authorize the caller against the path id. Returns the caller id. */
    private function ownerOnly(Request $req, array $args): int
    {
        $id       = (int) $args['id'];
        $callerId = (int) ($req->getHeaderLine('X-User-Id') ?: 0);
        if ($callerId === 0 || $callerId !== $id) {
            throw new DomainError(403, 'FORBIDDEN', 'Bạn chỉ có thể chỉnh sửa hồ sơ của chính mình.');
        }
        return $callerId;
    }

    private function validDate(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    }

    // ------------------------------------------------------------------ EXPERIENCE (PROF-03)

    public function addExperience(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $b = (array) ($req->getParsedBody() ?? []);

        $company = trim((string) ($b['company'] ?? ''));
        $title   = trim((string) ($b['title']   ?? ''));
        if ($company === '' || mb_strlen($company) > 160) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tên công ty từ 1-160 ký tự.');
        }
        if ($title === '' || mb_strlen($title) > 160) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Chức danh từ 1-160 ký tự.');
        }

        $startDate = trim((string) ($b['start_date'] ?? ''));
        if (!$this->validDate($startDate)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Ngày bắt đầu phải dạng YYYY-MM-DD.');
        }

        $endRaw  = $b['end_date'] ?? null;
        $endDate = ($endRaw === null || trim((string) $endRaw) === '') ? null : trim((string) $endRaw);
        if ($endDate !== null && !$this->validDate($endDate)) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Ngày kết thúc phải dạng YYYY-MM-DD.');
        }

        $descRaw = $b['description'] ?? null;
        $desc    = ($descRaw === null || trim((string) $descRaw) === '') ? null : (string) $descRaw;
        if ($desc !== null && mb_strlen($desc) > 5000) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Mô tả quá dài.');
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO experience (user_id, company, title, start_date, end_date, description)
             VALUES (:u, :c, :t, :sd, :ed, :de)'
        );
        $stmt->execute([
            ':u' => $caller, ':c' => $company, ':t' => $title,
            ':sd' => $startDate, ':ed' => $endDate, ':de' => $desc,
        ]);
        $eid = (int) $pdo->lastInsertId();

        return Json::ok($res, [
            'id' => $eid, 'company' => $company, 'title' => $title,
            'start_date' => $startDate, 'end_date' => $endDate, 'description' => $desc,
        ], 201);
    }

    public function updateExperience(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $eid    = (int) $args['eid'];
        $b      = (array) ($req->getParsedBody() ?? []);

        $sets   = [];
        $params = [':eid' => $eid, ':caller' => $caller];

        if (array_key_exists('company', $b)) {
            $company = trim((string) $b['company']);
            if ($company === '' || mb_strlen($company) > 160) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Tên công ty từ 1-160 ký tự.');
            }
            $sets[] = 'company = :c';
            $params[':c'] = $company;
        }
        if (array_key_exists('title', $b)) {
            $title = trim((string) $b['title']);
            if ($title === '' || mb_strlen($title) > 160) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Chức danh từ 1-160 ký tự.');
            }
            $sets[] = 'title = :t';
            $params[':t'] = $title;
        }
        if (array_key_exists('start_date', $b)) {
            $startDate = trim((string) $b['start_date']);
            if (!$this->validDate($startDate)) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Ngày bắt đầu phải dạng YYYY-MM-DD.');
            }
            $sets[] = 'start_date = :sd';
            $params[':sd'] = $startDate;
        }
        if (array_key_exists('end_date', $b)) {
            $endRaw  = $b['end_date'];
            $endDate = ($endRaw === null || trim((string) $endRaw) === '') ? null : trim((string) $endRaw);
            if ($endDate !== null && !$this->validDate($endDate)) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Ngày kết thúc phải dạng YYYY-MM-DD.');
            }
            $sets[] = 'end_date = :ed';
            $params[':ed'] = $endDate;
        }
        if (array_key_exists('description', $b)) {
            $descRaw = $b['description'];
            $desc    = ($descRaw === null || trim((string) $descRaw) === '') ? null : (string) $descRaw;
            if ($desc !== null && mb_strlen($desc) > 5000) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Mô tả quá dài.');
            }
            $sets[] = 'description = :de';
            $params[':de'] = $desc;
        }

        if ($sets === []) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Không có trường nào để cập nhật.');
        }

        $sql  = 'UPDATE experience SET ' . implode(', ', $sets) . ' WHERE id = :eid AND user_id = :caller';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            // IDOR-safe: not-found and not-owned are intentionally indistinguishable.
            throw new DomainError(404, 'EXPERIENCE_NOT_FOUND', 'Không tìm thấy mục kinh nghiệm.');
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, company, title, start_date, end_date, description
             FROM experience WHERE id = :eid AND user_id = :caller LIMIT 1'
        );
        $stmt->execute([':eid' => $eid, ':caller' => $caller]);
        return Json::ok($res, $stmt->fetch());
    }

    public function deleteExperience(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $eid    = (int) $args['eid'];

        $stmt = Db::pdo()->prepare('DELETE FROM experience WHERE id = :eid AND user_id = :caller');
        $stmt->execute([':eid' => $eid, ':caller' => $caller]);
        if ($stmt->rowCount() === 0) {
            throw new DomainError(404, 'EXPERIENCE_NOT_FOUND', 'Không tìm thấy mục kinh nghiệm.');
        }
        return Json::ok($res, ['deleted' => true]);
    }

    // ------------------------------------------------------------------ EDUCATION (PROF-04)

    private function validYear(mixed $v): ?int
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        if (!preg_match('/^\d{1,4}$/', trim((string) $v))) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Năm không hợp lệ.');
        }
        $year = (int) $v;
        if ($year < 1900 || $year > 2100) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Năm không hợp lệ.');
        }
        return $year;
    }

    public function addEducation(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $b = (array) ($req->getParsedBody() ?? []);

        $school = trim((string) ($b['school'] ?? ''));
        if ($school === '' || mb_strlen($school) > 160) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tên trường từ 1-160 ký tự.');
        }

        $degreeRaw = $b['degree'] ?? null;
        $degree    = ($degreeRaw === null || trim((string) $degreeRaw) === '') ? null : trim((string) $degreeRaw);
        if ($degree !== null && mb_strlen($degree) > 160) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Bằng cấp tối đa 160 ký tự.');
        }

        $fieldRaw = $b['field'] ?? null;
        $field    = ($fieldRaw === null || trim((string) $fieldRaw) === '') ? null : trim((string) $fieldRaw);
        if ($field !== null && mb_strlen($field) > 160) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Chuyên ngành tối đa 160 ký tự.');
        }

        $startYear = $this->validYear($b['start_year'] ?? null);
        $endYear   = $this->validYear($b['end_year'] ?? null);

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO education (user_id, school, degree, field, start_year, end_year)
             VALUES (:u, :s, :d, :f, :sy, :ey)'
        );
        $stmt->execute([
            ':u' => $caller, ':s' => $school, ':d' => $degree,
            ':f' => $field, ':sy' => $startYear, ':ey' => $endYear,
        ]);
        $eid = (int) $pdo->lastInsertId();

        return Json::ok($res, [
            'id' => $eid, 'school' => $school, 'degree' => $degree, 'field' => $field,
            'start_year' => $startYear, 'end_year' => $endYear,
        ], 201);
    }

    public function updateEducation(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $eid    = (int) $args['eid'];
        $b      = (array) ($req->getParsedBody() ?? []);

        $sets   = [];
        $params = [':eid' => $eid, ':caller' => $caller];

        if (array_key_exists('school', $b)) {
            $school = trim((string) $b['school']);
            if ($school === '' || mb_strlen($school) > 160) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Tên trường từ 1-160 ký tự.');
            }
            $sets[] = 'school = :s';
            $params[':s'] = $school;
        }
        if (array_key_exists('degree', $b)) {
            $degreeRaw = $b['degree'];
            $degree    = ($degreeRaw === null || trim((string) $degreeRaw) === '') ? null : trim((string) $degreeRaw);
            if ($degree !== null && mb_strlen($degree) > 160) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Bằng cấp tối đa 160 ký tự.');
            }
            $sets[] = 'degree = :d';
            $params[':d'] = $degree;
        }
        if (array_key_exists('field', $b)) {
            $fieldRaw = $b['field'];
            $field    = ($fieldRaw === null || trim((string) $fieldRaw) === '') ? null : trim((string) $fieldRaw);
            if ($field !== null && mb_strlen($field) > 160) {
                throw new DomainError(400, 'VALIDATION_FAILED', 'Chuyên ngành tối đa 160 ký tự.');
            }
            $sets[] = 'field = :f';
            $params[':f'] = $field;
        }
        if (array_key_exists('start_year', $b)) {
            $sets[] = 'start_year = :sy';
            $params[':sy'] = $this->validYear($b['start_year']);
        }
        if (array_key_exists('end_year', $b)) {
            $sets[] = 'end_year = :ey';
            $params[':ey'] = $this->validYear($b['end_year']);
        }

        if ($sets === []) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Không có trường nào để cập nhật.');
        }

        $sql  = 'UPDATE education SET ' . implode(', ', $sets) . ' WHERE id = :eid AND user_id = :caller';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new DomainError(404, 'EDUCATION_NOT_FOUND', 'Không tìm thấy mục học vấn.');
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, school, degree, field, start_year, end_year
             FROM education WHERE id = :eid AND user_id = :caller LIMIT 1'
        );
        $stmt->execute([':eid' => $eid, ':caller' => $caller]);
        return Json::ok($res, $stmt->fetch());
    }

    public function deleteEducation(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $eid    = (int) $args['eid'];

        $stmt = Db::pdo()->prepare('DELETE FROM education WHERE id = :eid AND user_id = :caller');
        $stmt->execute([':eid' => $eid, ':caller' => $caller]);
        if ($stmt->rowCount() === 0) {
            throw new DomainError(404, 'EDUCATION_NOT_FOUND', 'Không tìm thấy mục học vấn.');
        }
        return Json::ok($res, ['deleted' => true]);
    }

    // ------------------------------------------------------------------ SKILLS (PROF-05, add/remove only)

    public function addSkill(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $b      = (array) ($req->getParsedBody() ?? []);

        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Tên kỹ năng từ 1-80 ký tự.');
        }

        $pdo  = Db::pdo();
        try {
            $stmt = $pdo->prepare('INSERT INTO skills (user_id, name) VALUES (:caller, :name)');
            $stmt->execute([':caller' => $caller, ':name' => $name]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainError(409, 'SKILL_EXISTS', 'Kỹ năng đã tồn tại.');
            }
            throw $e;
        }
        $sid = (int) $pdo->lastInsertId();

        return Json::ok($res, ['id' => $sid, 'name' => $name], 201);
    }

    public function deleteSkill(Request $req, Response $res, array $args): Response
    {
        $caller = $this->ownerOnly($req, $args);
        $sid    = (int) $args['sid'];

        $stmt = Db::pdo()->prepare('DELETE FROM skills WHERE id = :sid AND user_id = :caller');
        $stmt->execute([':sid' => $sid, ':caller' => $caller]);
        if ($stmt->rowCount() === 0) {
            throw new DomainError(404, 'SKILL_NOT_FOUND', 'Không tìm thấy kỹ năng.');
        }
        return Json::ok($res, ['deleted' => true]);
    }
}
