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
