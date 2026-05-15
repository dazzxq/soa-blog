-- Tier 1 seed (chạy tự động khi init container lần đầu).
-- 1 tài khoản demo công khai + 4 thành viên nhóm đồ án.
-- Mật khẩu chung cho mọi tài khoản: "demo@123**" (bcrypt cost 12).

USE blog_users;
INSERT IGNORE INTO users (id, username, email, password_hash, display_name, avatar_url) VALUES
  (1, 'demo',  'demo@duyet.vn',  '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Tài khoản demo',     NULL),
  (2, 'duyet', 'duyet@duyet.vn', '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Nguyễn Thế Duyệt',   NULL),
  (3, 'long',  'long@duyet.vn',  '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Đinh Ngọc Long',     NULL),
  (4, 'diep',  'diep@duyet.vn',  '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Vũ Duy Điệp',        NULL),
  (5, 'tai',   'tai@duyet.vn',   '$2y$12$ALzJ3z471uDwTiudwJSMje8khpX0NQHKPTpoXRI9AhdIzFH6B5RaS', 'Tạ Ngọc Tài',        NULL);

USE blog_posts;
INSERT IGNORE INTO posts (id, author_id, title, slug, content) VALUES
  (1, 2, 'Chào mừng đến với SOA Blog', 'chao-mung-den-voi-soa-blog',
   'Đây là bài viết đầu tiên trên hệ thống blog viết theo kiến trúc microservices. Project sử dụng 3 service riêng biệt (user, post, comment) và một API Gateway điều hướng mọi request từ phía client.');

USE blog_comments;
INSERT IGNORE INTO comments (id, post_id, author_id, body) VALUES
  (1, 1, 3, 'Chúc mừng bài viết đầu tiên! Mong chờ những nội dung tiếp theo.');
