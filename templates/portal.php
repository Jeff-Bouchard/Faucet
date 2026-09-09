<?php
$exchangeApi = getenv('EXCHANGE_API_PATH') ?: '/exchange/api.php';
if (!preg_match('~^/(?!/)[a-zA-Z0-9/_-]+\\.php$~D', $exchangeApi)) { $exchangeApi = '/exchange/api.php'; }
$meme = 'assets/img/neoguns.jpeg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Claim a time token, cash out to your NESS wallet, and use the NVS exchange.">
<title>Privateness · Faucet & Exchange</title>
<link rel="stylesheet" href="assets/css/portal.css">
<script defer src="assets/js/portal.js"></script>
</head>
<body data-exchange-api="<?=htmlspecialchars($exchangeApi, ENT_QUOTES, 'UTF-8')?>">
<header><a class="brand" href="#faucet">PRIVATENESS<span>Faucet & Exchange</span></a><span class="network">NESS / NCH / EMC</span></header>
<main>
<nav aria-label="Choose a service">
<a href="#faucet" data-panel="faucet" aria-current="page">Faucet</a>
<a href="#cashout" data-panel="cashout">Cash out</a>
<a href="#exchange" data-panel="exchange">NCH exchange</a>
<a href="#nvs" data-panel="nvs">NVS records</a>
</nav>
<noscript><p class="notice">Enable JavaScript to use the countdown and shared interface.</p></noscript>
<section id="faucet" class="panel">
<div class="intro"><p class="eyebrow">A LITTLE TIME. A LITTLE NESS.</p><h1>Your next time token.</h1><p>Enjoy the meme for 10 seconds. Then copy your token or use it here.</p></div>
<div class="claim-layout">
<figure class="meme" id="meme-slot"><figcaption>WHEN THEY SAY<br>“TIME IS MONEY”</figcaption><img id="meme" src="<?=$meme?>" width="600" height="248" alt="Neo holding two weapons in The Matrix"><p>YOU BROUGHT A TIME TOKEN.</p></figure>
<div class="claim-controls"><span class="step-label">01 / CLAIM</span><div class="countdown"><strong id="count">10</strong><span id="count-label">seconds to unlock</span></div><progress id="progress" max="10" value="0" aria-label="Token countdown"></progress><p id="claim-status" role="status">Start when you’re ready.</p><button id="start">Show meme & start</button><label for="new-token">Your time token</label><textarea id="new-token" rows="3" readonly disabled placeholder="Your token appears after the countdown." spellcheck="false"></textarea><div class="actions"><button id="copy" class="secondary" disabled>Copy token</button><button id="use-token" disabled>Use for cash-out</button></div><button id="new-claim" class="text-button" hidden>Get another token</button><p class="small">Save your token to redeem later. Anyone holding it can claim its value.</p></div>
</div>
</section>
<section id="cashout" class="panel" hidden><div class="intro"><p class="eyebrow">02 / CASH OUT</p><h1>Time, meet your wallet.</h1><p>Paste a saved token or bring one across from the faucet.</p></div>
<form id="cashout-form" class="form-surface"><label for="cash-token">Time token</label><textarea id="cash-token" name="token" rows="3" required maxlength="160" spellcheck="false" autocomplete="off"></textarea><label for="cash-address">Receive at your NESS address</label><input id="cash-address" name="address" placeholder="NESS receiving address" required maxlength="64" autocomplete="off" spellcheck="false"><button id="quote" type="button" class="secondary">Check token value</button><div id="quote-result" class="notice" hidden></div><p class="small">The value can change as the token ages and other tokens are redeemed. Check the receiving address before submitting.</p><button id="withdraw" type="submit" disabled>Send to this address</button><p id="cash-status" role="status"></p></form>
</section>
<section id="exchange" class="panel" hidden><div class="intro"><p class="eyebrow">03 / EXCHANGE</p><h1>Exchange your NCH.</h1><p>Create an exchange request with your source address and NESS destination.</p></div>
<form id="exchange-form" class="form-surface"><label for="exchange-address">Address holding your NCH</label><input id="exchange-address" name="address" required maxlength="64" autocomplete="off" spellcheck="false"><label for="pay-address">Receive NESS at this address</label><input id="pay-address" name="pay_address" required maxlength="64" autocomplete="off" spellcheck="false"><p class="small">This creates the exchange’s WORM request. Your faucet time token is redeemed in Cash out.</p><button type="submit">Create exchange request</button><p id="exchange-status" role="status"></p></form><div id="exchange-slot" class="slot" hidden></div>
</section>
<section id="nvs" class="panel" hidden><div class="intro"><p class="eyebrow">04 / NAME–VALUE STORAGE</p><h1>Put a record on Emercoin.</h1><p>Enter your record, then choose EMC, NESS, or NCH to pay.</p></div>
<form id="nvs-form" class="form-surface"><label for="nvs-name">Record name</label><input id="nvs-name" name="name" required maxlength="512" placeholder="e.g. your namespace and record name" spellcheck="false"><label for="nvs-value">Record value</label><textarea id="nvs-value" name="value" rows="5" required maxlength="10240" spellcheck="false"></textarea><div class="field-row"><div><label for="nvs-days">Duration in days</label><input id="nvs-days" name="days" type="number" min="100" max="3650" value="100" required></div><div><label for="nvs-owner">Owner EMC address (optional)</label><input id="nvs-owner" name="owner" maxlength="64" spellcheck="false"></div></div><p class="small">Record contents will be public. Keep passwords, private keys, and cash-out tokens out of this field.</p><button type="submit">Create record request</button><p id="nvs-status" role="status"></p></form><div id="nvs-slot" class="slot" hidden></div>
</section>
<footer>Privateness Network <span>Keep your keys. Keep your tokens.</span></footer>
</main>
</body></html>
