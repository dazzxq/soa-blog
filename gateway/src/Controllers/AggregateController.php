<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\CommentClient;
use App\Services\PostClient;
use App\Services\UserClient;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/posts/{id}/full — flagship aggregation endpoint.
 *
 * Step 1 (sync):   GET post-service /posts/{id}
 * Step 2 (parallel): GET user-service /users/{author_id}
 *                    GET comment-service /comments?post_id={id}
 * Step 3 (sync):   batch GET user-service /users?ids=... for comment authors
 * Step 4: assemble {post + author + comments[*].author}
 */
final class AggregateController
{
    public function __construct(
        private PostClient $posts,
        private UserClient $users,
        private CommentClient $comments,
    ) {}

    public function postFull(Request $req, Response $res, array $args): Response
    {
        $postId = (int) $args['id'];

        // Step 1 — post is the core; if it's missing, 404 immediately.
        $postRes = $this->posts->get($postId);
        if ($postRes->getStatusCode() === 404) {
            throw new DomainError(404, 'POST_NOT_FOUND', 'Bài viết không tồn tại.');
        }
        if ($postRes->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($postRes), $postRes->getStatusCode());
        }
        $post = (array) ($this->decode($postRes)['data'] ?? []);
        $authorId = (int) ($post['author_id'] ?? 0);

        // Step 2 — parallel calls.
        $settled = Utils::settle([
            'author'   => $this->users->getAsync($authorId),
            'comments' => $this->comments->listByPostAsync($postId, ['per_page' => 100]),
        ])->wait();

        $degraded = [];

        // Author
        $author = null;
        if ($settled['author']['state'] === 'fulfilled' && $settled['author']['value']->getStatusCode() === 200) {
            $author = (array) ($this->decode($settled['author']['value'])['data'] ?? null);
        } else {
            $degraded[] = 'author';
        }

        // Comments
        $rawComments = [];
        if ($settled['comments']['state'] === 'fulfilled' && $settled['comments']['value']->getStatusCode() === 200) {
            $rawComments = (array) ($this->decode($settled['comments']['value'])['data'] ?? []);
        } else {
            $degraded[] = 'comments';
        }

        // Step 3 — batch fetch all comment authors.
        // Must degrade gracefully: user-service hiccup must NOT 500 the page.
        $commentAuthors = [];
        if ($rawComments !== []) {
            $ids = array_values(array_unique(array_map(
                static fn(array $c) => (int) $c['author_id'],
                $rawComments
            )));
            try {
                $batchRes = $this->users->batch($ids);
                if ($batchRes->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($batchRes)['data'] ?? []) as $u) {
                        $commentAuthors[(int) $u['id']] = $u;
                    }
                } else {
                    $degraded[] = 'comment_authors';
                }
            } catch (\Throwable) {
                $degraded[] = 'comment_authors';
            }
        }

        // Step 4 — assemble
        $comments = array_map(static function (array $c) use ($commentAuthors): array {
            $c['author'] = $commentAuthors[(int) $c['author_id']] ?? null;
            return $c;
        }, $rawComments);

        $post['author']   = $author;
        $post['comments'] = $comments;

        $body = ['data' => $post];
        if ($degraded !== []) {
            $body['meta'] = ['degraded' => true, 'parts' => $degraded];
        }

        $res->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function decode(\Psr\Http\Message\ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
