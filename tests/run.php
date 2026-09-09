<?php
require_once __DIR__ . '/../lib/ClaimGate.php';
require_once __DIR__ . '/../lib/Redemptions.php';
require_once __DIR__ . '/../lib/Sqlite.php';
require_once __DIR__ . '/../lib/TimeCryptor.php';

$count = 0;
function expect($condition, string $message): void {
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
    echo "PASS $message\n";
}
$claim = lib\ClaimGate::start([], 1000.25);
expect(!isset($claim['token']), 'claim contains no signed token');
expect(lib\ClaimGate::inspect($claim, $claim['id'], 1000.25)['remaining_ms'] === 10000, 'exact ten-second initial deadline');
expect(lib\ClaimGate::inspect($claim, $claim['id'], 1010.249)['remaining_ms'] > 0, 'release blocked immediately before deadline');
expect(lib\ClaimGate::inspect($claim, $claim['id'], 1010.25)['remaining_ms'] === 0, 'release permitted at deadline');
expect(lib\ClaimGate::start($claim, 1003, true) === $claim, 'early restart cannot bypass wait');
expect(lib\ClaimGate::start($claim, 1013) === $claim, 'reload recovers the original claim');
try { lib\ClaimGate::inspect($claim, str_repeat('a', 48), 1011); expect(false, 'foreign claim denied'); }
catch (InvalidArgumentException $e) { expect(true, 'foreign claim denied'); }
try { lib\ClaimGate::inspect($claim, $claim['id'], 1900.25); expect(false, 'expired claim denied'); }
catch (InvalidArgumentException $e) { expect(true, 'expired claim denied'); }
$claim['released'] = true;
expect(lib\ClaimGate::start($claim, 1012, true)['id'] !== $claim['id'], 'explicit new claim after release creates new wait');
$file = tempnam(sys_get_temp_dir(), 'faucet-test-');
try {
    $one = new lib\Redemptions($file);
    $two = new lib\Redemptions($file);
    expect($one->reserve(1000, 'recipient', 10), 'first payment reserves token');
    expect(!$two->reserve(1000, 'other-recipient', 10), 'second database connection cannot reserve same token');
    $one->finish(1000, 'review');
    expect(!$two->reserve(1000, 'recipient', 10), 'uncertain payment cannot be paid again');
    expect($two->get(1000)['state'] === 'review', 'review state persists across connections');
    $one->finish(1000, 'submitted', str_repeat('a', 64));
    expect($two->get(1000)['txid'] === str_repeat('a', 64), 'transaction receipt persists');
    $db = new lib\Sqlite($file);
    $db->store(900);
    expect($db->check(900), 'legacy spent-token database remains readable');
    if (function_exists('sodium_crypto_sign_seed_keypair')) {
        $seed = random_bytes(32);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $pub = sodium_crypto_sign_publickey($pair);
        $cryptor = new lib\TimeCryptor($db, $seed, $pub);
        $token = $cryptor->show(time() - 20);
        expect($cryptor->verify($token), 'legacy token signing and verification round trip');
        expect($cryptor->getLength($token) > 0, 'legacy time value remains positive');
        $usedAt = time() - 20;
        $used = $cryptor->show($usedAt); $cryptor->store($used);
        expect($cryptor->check($used), 'redeemed signed token is marked spent');
        foreach (['', '0_AA==', 'not_a_token', (time() + 20) . '_' . explode('_', $token)[1], str_replace('_', '__', $token)] as $bad) {
            try { $rejected = !$cryptor->verify($bad); }
            catch (\lib\err\EWrongTokenFormat $e) { $rejected = true; }
            expect($rejected, 'malformed/future token rejected');
        }
        expect($cryptor->getLength($cryptor->show(time())) === 0, 'zero-age quote terminates');
    } else { echo "SKIP sodium signing checks: sodium unavailable in this runtime\n"; }
} finally { unset($one, $two, $db); unlink($file); }
echo "$count checks passed\n";
