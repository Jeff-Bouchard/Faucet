<?php
namespace lib;

/** A durable reservation is committed before any wallet call. */
final class Redemptions
{
    private $db;

    public function __construct(string $path)
    {
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS redemptions (
            token_time INTEGER PRIMARY KEY, address TEXT NOT NULL, seconds INTEGER NOT NULL,
            state TEXT NOT NULL, txid TEXT, created INTEGER NOT NULL)');
    }

    public function get(int $time): ?array
    {
        $q = $this->db->prepare('SELECT address, seconds, state, txid FROM redemptions WHERE token_time = ?');
        $q->execute([$time]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function reserve(int $time, string $address, int $seconds): bool
    {
        $q = $this->db->prepare("INSERT OR IGNORE INTO redemptions (token_time,address,seconds,state,created) VALUES (?,?,?,'pending',?)");
        $q->execute([$time, $address, $seconds, time()]);
        return $q->rowCount() === 1;
    }

    public function finish(int $time, string $state, ?string $txid = null): void
    {
        if (!in_array($state, ['submitted', 'review'], true)) {
            throw new \InvalidArgumentException('Invalid redemption state');
        }
        $q = $this->db->prepare('UPDATE redemptions SET state = ?, txid = ? WHERE token_time = ?');
        $q->execute([$state, $txid, $time]);
    }
}
