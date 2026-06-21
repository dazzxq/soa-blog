<?php
declare(strict_types=1);

namespace App;

/**
 * Snowflake-style 64-bit id generator (decimal STRING output).
 *
 * Layout (khớp backfill SQL ở db/09 — cùng epoch, cùng 22 bit thấp):
 *   [ 41-bit ms kể từ 2020-01-01 ] [ 10-bit worker = PID & 0x3FF ] [ 12-bit sequence ]
 *
 * Worker id lấy từ PID nên hai PHP-FPM worker khác process → khác worker-bits →
 * không trùng dù sinh trong cùng millisecond. Lưới an toàn cuối cùng là ràng buộc
 * UNIQUE(post_id) + retry-on-duplicate ở chỗ INSERT (PostController).
 *
 * Trả về STRING: id có thể tiến gần 2^63; giữ chuỗi để JSON không serialize thành
 * Number (JS mất chính xác > 2^53).
 */
final class Snowflake
{
    private const EPOCH_MS    = 1577836800000; // 2020-01-01T00:00:00Z
    private const WORKER_BITS = 10;
    private const SEQ_BITS    = 12;

    private static int $lastMs = -1;
    private static int $seq    = 0;

    public static function next(): string
    {
        $worker = getmypid() & 0x3FF; // 10 bit

        $ms = (int) floor(microtime(true) * 1000);
        if ($ms === self::$lastMs) {
            self::$seq = (self::$seq + 1) & 0xFFF; // 12 bit
            if (self::$seq === 0) {
                // tràn sequence trong cùng ms → quay sang ms kế
                do {
                    $ms = (int) floor(microtime(true) * 1000);
                } while ($ms <= self::$lastMs);
            }
        } else {
            self::$seq = 0;
        }
        self::$lastMs = $ms;

        $id = (($ms - self::EPOCH_MS) << (self::WORKER_BITS + self::SEQ_BITS))
            | ($worker << self::SEQ_BITS)
            | self::$seq;

        return (string) $id;
    }
}
