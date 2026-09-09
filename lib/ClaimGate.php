<?php
namespace lib;

/** Server-owned deadlines. A claim never contains a signed token before release. */
final class ClaimGate
{
    const WAIT_SECONDS = 10;
    const TTL_SECONDS = 900;

    public static function start(array $claim, float $now, bool $new = false): array
    {
        if ($claim && $now < $claim['expires_at'] && (!$new || empty($claim['released']))) {
            return $claim;
        }
        return [
            'id' => bin2hex(random_bytes(24)),
            'token_time' => (int) floor($now),
            'available_at' => $now + self::WAIT_SECONDS,
            'expires_at' => $now + self::TTL_SECONDS,
            'released' => false,
        ];
    }

    public static function inspect(array $claim, string $id, float $now): array
    {
        if (!$claim || !hash_equals($claim['id'], $id) || $now >= $claim['expires_at']) {
            throw new \InvalidArgumentException('This claim expired. Start a new token.');
        }
        return [
            'claim_id' => $claim['id'],
            'remaining_ms' => (int) ceil(max(0, $claim['available_at'] - $now) * 1000),
            'wait_seconds' => self::WAIT_SECONDS,
        ];
    }
}
