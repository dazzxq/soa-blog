<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\ConnectionClient;
use App\Services\FeedClient;
use App\Services\NotificationClient;
use App\Services\ProfileClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /api/feed + /api/posts/* — the gateway feed composition (FEED-06 / D-06).
 *
 * feed() is the showcase API Composition: ONE client request → the gateway asks
 * THREE services (connection: who are my connections → feed: posts + counts →
 * profile: authors) and merges ONE newest-first timeline. It resolves repost
 * originals in ONE batch (no N+1), then enriches ALL authors (post authors ∪
 * original authors) in ONE union profile batch (email allowlisted), and degrades
 * safely (meta.degraded) on partial failure. feed-service is the only hard dep.
 *
 * Doctrine reused verbatim from AggregateController/ConnectionsController:
 *   - identity from the JWT-set attribute via me() (never a body user_id); the
 *     FeedClient maps it to X-User-Id (T-04-14).
 *   - every author card is allowlisted to {id,username,display_name,avatar_url};
 *     /users?ids= SELECTs email, so the gateway MUST allowlist (Pitfall 2 / T-04-13).
 *   - non-core failures degrade to null cards / original:null, never a 500.
 */
final class FeedController
{
    public function __construct(
        private FeedClient $feed,
        private ProfileClient $profiles,
        private ConnectionClient $connections,
        private NotificationClient $notifications,
    ) {}

    /**
     * GET /api/feed — the 3-service composition (5 steps, UNION-BATCH shape).
     *
     * FAN-OUT (A7 honesty): the fan-out is SEQUENTIAL by construction —
     * STEP 1 (connections) must precede STEP 2 (timeline, needs author ids);
     * STEP 3 (originals) must precede STEP 4 (the union author batch), because
     * the author union INCLUDES the original posts' authors. So this is ONE
     * profile batch over originals→union, ≤2 round trips after the spine — NOT a
     * profile batch run in parallel with the originals fetch (it cannot be).
     */
    public function feed(Request $req, Response $res): Response
    {
        $me    = $this->me($req);
        $limit = min(50, max(1, (int) ($req->getQueryParams()['limit'] ?? 20)));
        $degraded = [];

        // STEP 1 — author universe (self + accepted connections). connection-service
        // down → own-posts only + degrade 'connections' (it is NOT a hard dep).
        $authorIds = [$me];
        try {
            $cRes = $this->connections->listAccepted($me);
            if ($cRes->getStatusCode() === 200) {
                foreach ((array) ($this->decode($cRes)['data'] ?? []) as $row) {
                    $uid = (int) ($row['user_id'] ?? 0);
                    if ($uid > 0) {
                        $authorIds[] = $uid;
                    }
                }
            } else {
                $degraded[] = 'connections';
            }
        } catch (GuzzleException $e) {
            $degraded[] = 'connections';
        }
        $authorIds = array_values(array_unique($authorIds));

        // STEP 2 — posts + counts (HARD dependency / spine). non-200 → fail the feed.
        $tRes = $this->feed->timeline($authorIds, $me, $limit);
        if ($tRes->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($tRes), $tRes->getStatusCode());
        }
        $posts = (array) ($this->decode($tRes)['data'] ?? []);

        // STEP 3-5 — resolve repost originals (1 batch) + enrich author union +
        // assemble. Shared with profilePosts() via enrichPosts() (no duplication).
        $enriched = $this->enrichPosts($posts);
        $degraded = array_merge($degraded, $enriched['degraded']);

        $meta = [];
        if ($degraded !== []) {
            $meta = ['degraded' => true, 'parts' => array_values(array_unique($degraded))];
        }
        return Json::list($res, $enriched['posts'], $meta);
    }

    /**
     * GET /api/profiles/{id}/posts — bài viết của MỘT user (mô hình post công khai/
     * permalink; optMw). timeline 1-author + composition ĐẦY ĐỦ giống feed()
     * (repost originals + author enrich) qua enrichPosts(). Degrade an toàn.
     */
    public function profilePosts(Request $req, Response $res, array $args): Response
    {
        $viewer = (int) ($req->getAttribute('user_id') ?? 0);   // optional-auth: 0 = anon
        $userId = (int) $args['id'];
        $limit  = min(50, max(1, (int) ($req->getQueryParams()['limit'] ?? 20)));

        $tRes = $this->feed->timeline([$userId], $viewer, $limit);
        if ($tRes->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($tRes), $tRes->getStatusCode());
        }
        $posts    = (array) ($this->decode($tRes)['data'] ?? []);
        $enriched = $this->enrichPosts($posts);

        $meta = $enriched['degraded'] !== []
            ? ['degraded' => true, 'parts' => array_values(array_unique($enriched['degraded']))]
            : [];
        return Json::list($res, $enriched['posts'], $meta);
    }

    /**
     * STEP 3-5 chung cho feed() và profilePosts(): resolve repost originals (1 batch,
     * no N+1) → enrich author UNION (post ∪ original, email allowlisted) → assemble in
     * place (giữ thứ tự newest-first, original:null khi gốc đã xoá). KHÔNG ném 500 —
     * mọi lỗi non-core đẩy vào 'degraded'. Trả ['posts'=>array, 'degraded'=>string[]].
     */
    private function enrichPosts(array $posts): array
    {
        $degraded = [];

        $originalIds = [];
        foreach ($posts as $p) {
            $rid = (int) ($p['repost_of'] ?? 0);
            if ($rid > 0) {
                $originalIds[] = $rid;
            }
        }
        $originalIds   = array_values(array_unique($originalIds));
        $originalsById = [];
        if ($originalIds !== []) {
            try {
                $oRes = $this->feed->getPosts($originalIds);
                if ($oRes->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($oRes)['data'] ?? []) as $orow) {
                        $originalsById[(int) ($orow['id'] ?? 0)] = $orow;
                    }
                } else {
                    $degraded[] = 'reposts';
                }
            } catch (GuzzleException $e) {
                $degraded[] = 'reposts';
            }
        }

        $authorUnion = [];
        foreach ($posts as $p) {
            $authorUnion[] = (int) ($p['author_id'] ?? 0);
        }
        foreach ($originalsById as $orow) {
            $authorUnion[] = (int) ($orow['author_id'] ?? 0);
        }
        $authorUnion = array_values(array_unique(array_filter($authorUnion, static fn($x) => (int) $x > 0)));

        $cardsById = [];
        if ($authorUnion !== []) {
            try {
                $pRes = $this->profiles->batch($authorUnion);
                if ($pRes->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($pRes)['data'] ?? []) as $u) {
                        $cardsById[(int) ($u['id'] ?? 0)] = $this->allowlist($u);
                    }
                } else {
                    $degraded[] = 'profiles';
                }
            } catch (GuzzleException $e) {
                $degraded[] = 'profiles';
            }
        }

        $out = [];
        foreach ($posts as $p) {
            $p['author'] = $cardsById[(int) ($p['author_id'] ?? 0)] ?? null;
            $rid = (int) ($p['repost_of'] ?? 0);
            if ($rid > 0 && isset($originalsById[$rid])) {
                $orow = $originalsById[$rid];
                $orow['author'] = $cardsById[(int) ($orow['author_id'] ?? 0)] ?? null;
                $p['original'] = $orow;
            } elseif ($rid > 0) {
                $p['original'] = null;
            }
            $out[] = $p;
        }
        return ['posts' => $out, 'degraded' => $degraded];
    }

    // --- Mutations (thin passthrough; me() → X-User-Id via FeedClient) --------
    // The missing-post 404, reaction-type validation, and owner-scope all live
    // in feed-service (Plan 02). The gateway is a thin authenticated passthrough.

    public function createPost(Request $req, Response $res): Response
    {
        $me = $this->me($req);
        $up = $this->feed->createPost($me, (array) ($req->getParsedBody() ?? []));
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    public function updatePost(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->feed->updatePost($me, (int) $args['id'], (array) ($req->getParsedBody() ?? []));
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    public function deletePost(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->feed->deletePost($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    public function repost(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->feed->repost($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    public function react(Request $req, Response $res, array $args): Response
    {
        $me   = $this->me($req);
        $type = trim((string) ((array) ($req->getParsedBody() ?? []))['type'] ?? '');
        if ($type === '') {
            $type = 'like';
        }
        $up   = $this->feed->react($me, (int) $args['id'], $type);
        $code = $up->getStatusCode();
        // Best-effort notify the POST author (D-05) — only on a 2xx write.
        if ($code === 200 || $code === 201) {
            $this->notifyPostAuthor((int) $args['id'], $me, 'reaction');
        }
        return Json::raw($res, $this->decode($up), $code);
    }

    public function unreact(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->feed->unreact($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    public function addComment(Request $req, Response $res, array $args): Response
    {
        $me   = $this->me($req);
        $body = (string) (((array) ($req->getParsedBody() ?? []))['body'] ?? '');
        $up   = $this->feed->addComment($me, (int) $args['id'], $body);
        $code = $up->getStatusCode();
        // Best-effort notify the POST author (D-05) — only on a 2xx write.
        if ($code === 200 || $code === 201) {
            $this->notifyPostAuthor((int) $args['id'], $me, 'comment');
        }
        return Json::raw($res, $this->decode($up), $code);
    }

    /**
     * Best-effort notify the POST author after a successful react/comment (D-05).
     *
     * The recipient is the post author resolved via getPost($id).author_id — NOT
     * the upstream react/comment response (which carries the ACTOR's row, Pitfall 2
     * / T-05-17). Self-notify is skipped. The getPost lookup + the create both live
     * inside one swallowing try/catch: a notify/getPost failure NEVER changes the
     * react/comment outcome (which already returned 2xx, T-05-16).
     */
    private function notifyPostAuthor(int $postId, int $actor, string $type): void
    {
        try {
            $pr = $this->feed->getPost($postId, 0);
            if ($pr->getStatusCode() !== 200) {
                return;
            }
            $authorId = (int) ($this->decode($pr)['data']['author_id'] ?? 0);
            if ($authorId <= 0 || $authorId === $actor) {
                return;   // skip self / invalid (D-05, Pitfall 2)
            }
            $this->notifications->create($authorId, $actor, $type, $postId);   // ref_id = postId
        } catch (GuzzleException $e) {
            // swallow — best-effort (D-05); the react/comment already returned 2xx.
        }
    }

    public function updateComment(Request $req, Response $res, array $args): Response
    {
        $me   = $this->me($req);
        $body = (string) (((array) ($req->getParsedBody() ?? []))['body'] ?? '');
        $up   = $this->feed->updateComment($me, (int) $args['id'], $body);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    public function deleteComment(Request $req, Response $res, array $args): Response
    {
        $me = $this->me($req);
        $up = $this->feed->deleteComment($me, (int) $args['id']);
        return Json::raw($res, $this->decode($up), $up->getStatusCode());
    }

    /** GET /api/posts/{id}/comments — enriched comment authors (email allowlist). */
    public function listComments(Request $req, Response $res, array $args): Response
    {
        $up = $this->feed->listComments((int) $args['id']);
        if ($up->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($up), $up->getStatusCode());
        }
        $rows = (array) ($this->decode($up)['data'] ?? []);

        $ids = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['author_id'] ?? 0);
            if ($aid > 0) {
                $ids[] = $aid;
            }
        }
        $ids = array_values(array_unique($ids));

        $cardsById = [];
        $degraded  = false;
        if ($ids !== []) {
            try {
                $pRes = $this->profiles->batch($ids);
                if ($pRes->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($pRes)['data'] ?? []) as $u) {
                        $cardsById[(int) ($u['id'] ?? 0)] = $this->allowlist($u);
                    }
                } else {
                    $degraded = true;
                }
            } catch (GuzzleException $e) {
                $degraded = true;
            }
        }

        $out = array_map(function (array $r) use ($cardsById): array {
            $r['author'] = $cardsById[(int) ($r['author_id'] ?? 0)] ?? null;
            return $r;
        }, $rows);

        return Json::list($res, $out, $degraded ? ['degraded' => true, 'parts' => ['profiles']] : []);
    }

    /**
     * GET /api/posts/{id} — single-post composition (optional-auth viewer).
     * Single-row variant of the feed assembly: resolve the original (if any),
     * then enrich {post author ∪ original author} (allowlist), attach + degrade.
     */
    public function showPost(Request $req, Response $res, array $args): Response
    {
        $viewer = (int) ($req->getAttribute('user_id') ?? 0);   // optional-auth: 0 = anon
        $up     = $this->feed->getPost((int) $args['id'], $viewer);
        if ($up->getStatusCode() !== 200) {
            return Json::raw($res, $this->decode($up), $up->getStatusCode());
        }
        $post = (array) ($this->decode($up)['data'] ?? []);
        $degraded = [];

        // Resolve the original (single-row repost case).
        $original = null;
        $rid = (int) ($post['repost_of'] ?? 0);
        if ($rid > 0) {
            try {
                $oRes = $this->feed->getPosts([$rid]);
                if ($oRes->getStatusCode() === 200) {
                    $orows = (array) ($this->decode($oRes)['data'] ?? []);
                    foreach ($orows as $orow) {
                        if ((int) ($orow['id'] ?? 0) === $rid) {
                            $original = $orow;
                            break;
                        }
                    }
                } else {
                    $degraded[] = 'reposts';
                }
            } catch (GuzzleException $e) {
                $degraded[] = 'reposts';
            }
        }

        // ONE profile batch over {post author ∪ original author} (single-post union).
        $union = [(int) ($post['author_id'] ?? 0)];
        if ($original !== null) {
            $union[] = (int) ($original['author_id'] ?? 0);
        }
        $union = array_values(array_unique(array_filter($union, static fn($x) => (int) $x > 0)));

        $cardsById = [];
        if ($union !== []) {
            try {
                $pRes = $this->profiles->batch($union);
                if ($pRes->getStatusCode() === 200) {
                    foreach ((array) ($this->decode($pRes)['data'] ?? []) as $u) {
                        $cardsById[(int) ($u['id'] ?? 0)] = $this->allowlist($u);
                    }
                } else {
                    $degraded[] = 'profiles';
                }
            } catch (GuzzleException $e) {
                $degraded[] = 'profiles';
            }
        }

        $post['author'] = $cardsById[(int) ($post['author_id'] ?? 0)] ?? null;
        if ($rid > 0 && $original !== null) {
            $original['author'] = $cardsById[(int) ($original['author_id'] ?? 0)] ?? null;
            $post['original'] = $original;
        } elseif ($rid > 0) {
            $post['original'] = null;   // deleted original (Pitfall 5)
        }

        $body = ['data' => $post];
        if ($degraded !== []) {
            $body['meta'] = ['degraded' => true, 'parts' => array_values(array_unique($degraded))];
        }
        $res->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    // --- Helpers -------------------------------------------------------------

    /** Resolve the authenticated caller from the JWT-set attribute (never the body). */
    private function me(Request $req): int
    {
        $me = (int) ($req->getAttribute('user_id') ?? 0);
        if ($me <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Vui lòng đăng nhập.');
        }
        return $me;
    }

    private function decode(ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Public-safe author card. DROPS email (Pitfall 2 / T-04-13): only
     * id, username, display_name, avatar_url ever reach the client.
     */
    private function allowlist(array $u): array
    {
        return array_intersect_key($u, array_flip(['id', 'username', 'display_name', 'avatar_url']));
    }
}
