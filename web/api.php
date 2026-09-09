<?php
require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/ClaimGate.php';
require_once __DIR__ . '/../lib/Redemptions.php';
use lib\Http;
use lib\ClaimGate;
use lib\Redemptions;

try {
    Http::session('privateness_faucet');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Http::json(['csrf' => $_SESSION['csrf'], 'wait_seconds' => ClaimGate::WAIT_SECONDS]);
    }
    $input = Http::input();
    $action = Http::text($input, 'action', 32);
    if ($action === 'start') {
        $_SESSION['claim'] = ClaimGate::start($_SESSION['claim'] ?? [], microtime(true), !empty($input['new']));
        Http::json(ClaimGate::inspect($_SESSION['claim'], $_SESSION['claim']['id'], microtime(true)));
    }
    if ($action === 'release') {
        $claim = $_SESSION['claim'] ?? [];
        $state = ClaimGate::inspect($claim, Http::text($input, 'claim_id', 48), microtime(true));
        if ($state['remaining_ms'] > 0) {
            header('Retry-After: ' . (int) ceil($state['remaining_ms'] / 1000));
            Http::json($state, 425);
        }
        require_once __DIR__ . '/../lib/Container.php';
        $token = lib\Container::Cryptor()->show($claim['token_time']);
        $_SESSION['claim']['released'] = true;
        Http::json($state + ['token' => $token]);
    }
    if (!in_array($action, ['quote', 'withdraw'], true)) {
        throw new InvalidArgumentException('Unknown action.');
    }
    require_once __DIR__ . '/../lib/Container.php';
    $token = Http::text($input, 'token', 160);
    $cryptor = lib\Container::Cryptor();
    if (!$cryptor->verify($token)) {
        throw new InvalidArgumentException('The time token could not be verified.');
    }
    $tokenTime = $cryptor->getTime($token);
    if (time() - $tokenTime < ClaimGate::WAIT_SECONDS) {
        throw new InvalidArgumentException('The time token is not ready yet.');
    }
    $redemptions = new Redemptions(__DIR__ . '/../data/tokens.db');
    $existing = $redemptions->get($tokenTime);
    if ($existing) {
        if ($action === 'withdraw' && hash_equals($existing['address'], Http::text($input, 'address', 64))) {
            Http::json(['redemption' => $existing], $existing['state'] === 'submitted' ? 200 : 409);
        }
        Http::json(['error' => 'This token is already claimed or awaiting payment review.'], 409);
    }
    if ($cryptor->check($token)) {
        Http::json(['error' => 'This token has already been redeemed.'], 409);
    }
    $crypto = lib\Container::Crypto();
    $seconds = $cryptor->getLength($token);
    $quote = ['seconds' => $seconds, 'ness' => number_format($crypto->priceCoins($seconds), 6, '.', ''), 'nch' => $crypto->priceHours($seconds)];
    if ($action === 'quote') {
        Http::json(['quote' => $quote]);
    }
    $address = Http::text($input, 'address', 64);
    if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{20,64}$/D', $address)) {
        throw new InvalidArgumentException('Enter a valid NESS receiving address.');
    }
    if (!$redemptions->reserve($tokenTime, $address, $seconds)) {
        Http::json(['error' => 'This token is already being processed.'], 409);
    }
    session_write_close();
    try {
        // Mark used before external I/O. Never automatically retry an uncertain broadcast.
        $cryptor->store($token);
        $txid = $crypto->pay($seconds, $address);
        $redemptions->finish($tokenTime, 'submitted', $txid);
    } catch (Throwable $error) {
        $redemptions->finish($tokenTime, 'review');
        Http::json(['error' => 'Payment needs operator review. Do not submit this token again.', 'reference' => $tokenTime], 503);
    }
    Http::json(['redemption' => $redemptions->get($tokenTime), 'quote' => $quote]);
} catch (InvalidArgumentException $error) {
    Http::json(['error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Http::json(['error' => 'The service is temporarily unavailable. Please try again later.'], 503);
}
