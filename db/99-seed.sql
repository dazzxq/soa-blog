-- Tier 1 seed (chạy tự động khi init container lần đầu).
-- 1 tài khoản demo công khai + 4 thành viên nhóm đồ án.
-- Mật khẩu chung cho mọi tài khoản: "demo@123**" (bcrypt cost 12).

USE proconnect_profile;
INSERT IGNORE INTO users (id, username, email, password_hash, display_name, avatar_url) VALUES
  (1, 'demo',  'demo@duyet.vn',  '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Tài khoản demo',     NULL),
  (2, 'duyet', 'duyet@duyet.vn', '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Nguyễn Thế Duyệt',   NULL),
  (3, 'long',  'long@duyet.vn',  '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Đinh Ngọc Long',     NULL),
  (4, 'diep',  'diep@duyet.vn',  '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Vũ Duy Điệp',        NULL),
  (5, 'tai',   'tai@duyet.vn',   '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Tạ Ngọc Tài',        NULL);

-- Phase 2 demo content (mirror of the guarded seed in db/02-migrate-phase2.sql)
-- so a fresh volume also gets sample profile data. INSERT IGNORE keyed on the
-- same explicit ids → idempotent and identical to the live-volume seed.
UPDATE users SET headline = 'Tài khoản demo — ProConnect',
                 location = 'Hà Nội, Việt Nam',
                 about    = 'Tài khoản demo công khai để thử nghiệm ProConnect.'
 WHERE id = 1 AND headline IS NULL;
UPDATE users SET headline = 'Sinh viên PTIT — Software Engineer',
                 location = 'Hà Nội, Việt Nam',
                 about    = 'Sinh viên Công nghệ thông tin tại Học viện Công nghệ Bưu chính Viễn thông, quan tâm tới kiến trúc hướng dịch vụ và API Gateway.'
 WHERE id = 2 AND headline IS NULL;

INSERT IGNORE INTO experience (id, user_id, company, title, start_date, end_date, description) VALUES
  (1, 2, 'Học viện Công nghệ Bưu chính Viễn thông', 'Sinh viên Công nghệ thông tin', '2022-09-01', NULL, 'Học và thực hành kiến trúc microservices, API Gateway pattern (môn INT1448 SOA).'),
  (2, 2, 'ProConnect (đồ án môn học)', 'Backend Developer', '2024-02-01', '2024-06-30', 'Xây dựng gateway điều phối các microservices: profile, connection, feed, search.'),
  (3, 1, 'Công ty Demo', 'Kỹ sư phần mềm', '2023-01-01', NULL, 'Tài khoản demo — kinh nghiệm mẫu để minh hoạ hồ sơ.');

INSERT IGNORE INTO education (id, user_id, school, degree, field, start_year, end_year) VALUES
  (1, 2, 'Học viện Công nghệ Bưu chính Viễn thông', 'Kỹ sư', 'Công nghệ thông tin', 2022, 2027),
  (2, 1, 'Trường Demo', 'Cử nhân', 'Khoa học máy tính', 2019, 2023);

INSERT IGNORE INTO skills (id, user_id, name) VALUES
  (1, 2, 'PHP'),
  (2, 2, 'Docker'),
  (3, 2, 'Kiến trúc hướng dịch vụ'),
  (4, 1, 'JavaScript');
