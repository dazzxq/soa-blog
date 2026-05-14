<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\CommentClient;
use App\Services\PostClient;
use App\Services\UserClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostsController
{
    public function __construct(
        private PostClient $posts,
        private UserClient $users,
        private CommentClient $comments,
    ) {}

    /**
     * GET /api/posts — list + enrich each post with its author.
     */
    public function index(Request $req, Response $res): Response
    {
        $upstream = $this->posts->list($req->getQueryParams());
        $payload  = $this->decode($upstream);
        if ($upstream->getStatusCode() !== 200) {
            return Json::raw($res, $payload, $upstream->getStatusCode());
        }

        $posts   = (array) ($payload['data'] ?? []);
        $authors = $this->fetchAuthors($posts);

        $out = array_map(static function (array $p) use ($authors): array {
            $p['author'] = $authors[(int) $p['author_id']] ?? null;
            return $p;
        }, $posts);

        return Json::list($res, $out, (array) ($payload['meta'] ?? []));
    }

    /**
     * GET /api/posts/{id} — single post + author.
     */
    public function show(Request $req, Response $res, array $args): Response
    {
        $upstream = $this->posts->get((int) $args['id']);
        $payload  = $this->decode($upstream);
        if ($upstream->getStatusCode() !== 200) {
            return Json::raw($res, $payload, $upstream->getStatusCode());
        }
        $post = (array) $payload['data'];

        $authorRes = $this->users->get((int) $post['author_id']);
        $post['author'] = $authorRes->getStatusCode() === 200
            ? (array) ($this->decode($authorRes)['data'] ?? null)
            : null;

        return Json::ok($res, $post);
    }

    /**
     * POST /api/posts — forward create with X-User-Id.
     */
    public function create(Request $req, Response $res): Response
    {
        $authorId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($authorId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        $upstream = $this->posts->create($authorId, (array) ($req->getParsedBody() ?? []));
        return Json::raw($res, $this->decode($upstream), $upstream->getStatusCode());
    }

    public function update(Request $req, Response $res, array $args): Response
    {
        $authorId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($authorId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        $upstream = $this->posts->update((int) $args['id'], $authorId, (array) ($req->getParsedBody() ?? []));
        return Json::raw($res, $this->decode($upstream), $upstream->getStatusCode());
    }

    /**
     * DELETE /api/posts/{id} — COMPOSITION:
     *  1. gateway calls comment-service to count comments
     *  2. if > 0 → 409 POST_HAS_COMMENTS (gateway owns this invariant)
     *  3. else forward DELETE to post-service
     */
    public function delete(Request $req, Response $res, array $args): Response
    {
        $callerId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($callerId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        $postId = (int) $args['id'];

        // The gateway must positively confirm count === 0 before delegating
        // the delete. Any upstream failure becomes a hard 503 — we cannot
        // create orphans by deleting on incomplete information.
        try {
            $countRes = $this->comments->countByPost($postId);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new DomainError(503, 'COMMENT_SERVICE_UNAVAILABLE',
                'Không kiểm tra được số bình luận của bài viết. Vui lòng thử lại.');
        }
        if ($countRes->getStatusCode() !== 200) {
            throw new DomainError(503, 'COMMENT_SERVICE_UNAVAILABLE',
                'Không kiểm tra được số bình luận của bài viết. Vui lòng thử lại.');
        }
        $count = (int) ($this->decode($countRes)['data']['count'] ?? 0);
        if ($count > 0) {
            throw new DomainError(409, 'POST_HAS_COMMENTS',
                "Không thể xoá bài viết khi còn $count bình luận. Hãy xoá bình luận trước.");
        }

        $upstream = $this->posts->delete($postId, $callerId);
        $status   = $upstream->getStatusCode();
        if ($status === 204) {
            return $res->withStatus(204);
        }
        return Json::raw($res, $this->decode($upstream), $status);
    }

    /**
     * GET /api/posts/{id}/comments — list + enrich each comment with author.
     */
    public function comments(Request $req, Response $res, array $args): Response
    {
        $postId = (int) $args['id'];
        $upstream = $this->comments->listByPost($postId, $req->getQueryParams());
        $payload  = $this->decode($upstream);
        if ($upstream->getStatusCode() !== 200) {
            return Json::raw($res, $payload, $upstream->getStatusCode());
        }
        $comments = (array) ($payload['data'] ?? []);
        $authors  = $this->fetchAuthors($comments);

        $out = array_map(static function (array $c) use ($authors): array {
            $c['author'] = $authors[(int) $c['author_id']] ?? null;
            return $c;
        }, $comments);

        return Json::list($res, $out, (array) ($payload['meta'] ?? []));
    }

    /**
     * POST /api/posts/{id}/comments — COMPOSITION:
     *  1. gateway verifies post exists via post-service
     *  2. forward POST /comments
     */
    public function createComment(Request $req, Response $res, array $args): Response
    {
        $authorId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($authorId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }

        $postId = (int) $args['id'];
        $check  = $this->posts->get($postId);
        if ($check->getStatusCode() === 404) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        if ($check->getStatusCode() >= 500) {
            return Json::raw($res, $this->decode($check), $check->getStatusCode());
        }

        $b    = (array) ($req->getParsedBody() ?? []);
        $body = trim((string) ($b['body'] ?? ''));

        $upstream = $this->comments->create($authorId, $postId, $body);
        return Json::raw($res, $this->decode($upstream), $upstream->getStatusCode());
    }

    public function deleteComment(Request $req, Response $res, array $args): Response
    {
        $callerId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($callerId === 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        $upstream = $this->comments->delete((int) $args['id'], $callerId);
        $status = $upstream->getStatusCode();
        if ($status === 204) return $res->withStatus(204);
        return Json::raw($res, $this->decode($upstream), $status);
    }

    /**
     * @param list<array{author_id:int|string}> $rows
     * @return array<int,array> author keyed by id
     */
    private function fetchAuthors(array $rows): array
    {
        $ids = array_values(array_unique(array_map(
            static fn(array $r) => (int) $r['author_id'],
            $rows
        )));
        if ($ids === []) return [];

        $res = $this->users->batch($ids);
        if ($res->getStatusCode() !== 200) return [];
        $payload = $this->decode($res);

        $out = [];
        foreach ((array) ($payload['data'] ?? []) as $u) {
            $out[(int) $u['id']] = $u;
        }
        return $out;
    }

    private function decode(\Psr\Http\Message\ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
