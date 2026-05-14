-- Tier 1 seed (luôn chạy khi init container lần đầu).
-- Mật khẩu mọi tài khoản demo: "demo123" (bcrypt cost 12).

USE blog_users;
INSERT IGNORE INTO users (id, username, email, password_hash, display_name, avatar_url) VALUES
  (1, 'alice', 'alice@duyet.vn', '$2y$12$aD63zZY9eZZ12KpViLB5M.cwT1/R.9G/A8.nUW0FQh4wU7m17iuPq', 'Alice Nguyễn',  NULL),
  (2, 'bob',   'bob@duyet.vn',   '$2y$12$aD63zZY9eZZ12KpViLB5M.cwT1/R.9G/A8.nUW0FQh4wU7m17iuPq', 'Bob Trần',      NULL);

USE blog_posts;
INSERT IGNORE INTO posts (id, author_id, title, slug, content) VALUES
  (1, 1, 'Chào mừng đến với SOA Blog', 'chao-mung-den-voi-soa-blog',
   'Đây là bài viết đầu tiên trên hệ thống blog viết theo kiến trúc microservices. Project sử dụng 3 service riêng biệt (user, post, comment) và một API Gateway điều hướng mọi request từ phía client.');

USE blog_comments;
INSERT IGNORE INTO comments (id, post_id, author_id, body) VALUES
  (1, 1, 2, 'Chúc mừng bài viết đầu tiên! Mong chờ những nội dung tiếp theo.');
