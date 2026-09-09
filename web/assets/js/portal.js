'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const faucetApi = new URL('api.php', location.href).pathname;
  const exchangeApi = document.body.dataset.exchangeApi;
  const csrf = new Map();
  let claimId = '', token = '', running = false, remaining = 10000, lastTick = 0;
  const status = (id, message, error = false) => {
    $(id).textContent = message;
    $(id).classList.toggle('error', error);
  };
  async function api(url, data) {
    if (!csrf.has(url)) {
      const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
      const session = await response.json();
      if (!response.ok || !session.csrf) throw new Error(session.error || 'Service unavailable. Try again later.');
      csrf.set(url, session.csrf);
    }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 90000);
    let response;
    try {
      response = await fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf.get(url)}, body: JSON.stringify(data)});
    } catch (error) {
      throw new Error(data.action === 'withdraw' ? 'The payment result is unknown. Check with the operator before retrying.' : 'Connection interrupted. Please try again.');
    } finally { clearTimeout(timeout); }
    const result = await response.json();
    if (response.status === 403) csrf.delete(url);
    if (response.status === 425) return result;
    if (!response.ok) throw new Error(result.error || (result.redemption ? 'This payment is pending or needs operator review.' : 'Service unavailable. Please try again.'));
    return result;
  }
  function navigate() {
    const name = location.hash.slice(1) || 'faucet';
    const active = ['faucet', 'cashout', 'exchange', 'nvs'].includes(name) ? name : 'faucet';
    document.querySelectorAll('.panel').forEach(panel => { panel.hidden = panel.id !== active; });
    document.querySelectorAll('[data-panel]').forEach(link => {
      if (link.dataset.panel === active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    lastTick = performance.now();
  }
  window.addEventListener('hashchange', navigate);
  document.addEventListener('visibilitychange', () => { lastTick = performance.now(); });
  navigate();
  async function start(fresh = false) {
    if (running) return;
    running = true;
    $('start').disabled = true;
    $('new-claim').hidden = true;
    $('copy').disabled = $('use-token').disabled = true;
    $('new-token').disabled = true;
    $('new-token').value = token = '';
    try {
      if (!$('meme').complete) await new Promise((resolve, reject) => {
        $('meme').addEventListener('load', resolve, {once: true});
        $('meme').addEventListener('error', () => reject(new Error('The meme did not load. Reload the page to try again.')), {once: true});
      });
      if (!$('meme').naturalWidth) throw new Error('The meme did not load. Reload the page to try again.');
      const result = await api(faucetApi, {action: 'start', new: fresh});
      claimId = result.claim_id;
      remaining = result.remaining_ms;
      lastTick = performance.now();
      status('claim-status', 'Enjoy the meme. Your token is on its way.');
      $('start').hidden = true;
      tick();
    } catch (error) { claimError(error); }
  }
  function claimError(error) {
    running = false;
    $('start').hidden = false;
    $('start').disabled = false;
    $('start').textContent = 'Retry claim';
    status('claim-status', error.message, true);
  }
  async function tick() {
    if (!running) return;
    const now = performance.now();
    if (!document.hidden && !$('faucet').hidden) remaining = Math.max(0, remaining - (now - lastTick));
    lastTick = now;
    $('count').textContent = String(Math.ceil(remaining / 1000));
    $('progress').value = 10 - remaining / 1000;
    if (remaining > 0) { setTimeout(tick, 100); return; }
    status('claim-status', 'Unlocking your token…');
    try {
      const result = await api(faucetApi, {action: 'release', claim_id: claimId});
      if (!result.token) {
        remaining = Math.max(100, result.remaining_ms);
        lastTick = performance.now();
        setTimeout(tick, 100);
        return;
      }
      token = result.token;
      $('new-token').disabled = false;
      $('new-token').value = token;
      $('copy').disabled = $('use-token').disabled = false;
      $('new-claim').hidden = false;
      $('count').textContent = '✓';
      $('count-label').textContent = 'ready to use';
      status('claim-status', 'Token ready. Copy it or cash out here.');
      running = false;
    } catch (error) { claimError(error); }
  }
  $('start').addEventListener('click', () => start());
  $('new-claim').addEventListener('click', () => { $('count-label').textContent = 'seconds to unlock'; start(true); });
  $('copy').addEventListener('click', async () => {
    if (!token) return;
    try {
      if (!navigator.clipboard) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(token);
      status('claim-status', 'Copied. Keep the token somewhere private.');
    } catch (_) {
      $('new-token').focus(); $('new-token').select();
      status('claim-status', 'Token selected. Use your browser’s Copy command.');
    }
  });
  $('use-token').addEventListener('click', () => {
    if (!token) return;
    $('cash-token').value = token;
    clearQuote();
    location.hash = 'cashout';
    $('cash-address').focus();
  });
  function clearQuote() { $('withdraw').disabled = true; $('quote-result').hidden = true; }
  $('cash-token').addEventListener('input', clearQuote);
  $('cash-address').addEventListener('input', clearQuote);
  $('quote').addEventListener('click', async () => {
    $('quote').disabled = true;
    $('withdraw').disabled = true;
    const checkedToken = $('cash-token').value.trim();
    status('cash-status', 'Checking token…');
    try {
      const result = await api(faucetApi, {action: 'quote', token: checkedToken});
      if ($('cash-token').value.trim() !== checkedToken) return;
      $('quote-result').textContent = `${result.quote.ness} NESS + ${result.quote.nch} NCH · ${result.quote.seconds} credited seconds`;
      $('quote-result').hidden = false;
      $('withdraw').disabled = false;
      status('cash-status', 'Token verified. Check your address, then send.');
    } catch (error) { status('cash-status', error.message, true); }
    finally { $('quote').disabled = false; }
  });
  $('cashout-form').addEventListener('submit', async event => {
    event.preventDefault();
    if ($('withdraw').disabled) return;
    $('withdraw').disabled = true;
    $('quote').disabled = true;
    const address = $('cash-address').value.trim();
    status('cash-status', 'Submitting your payment…');
    try {
      const result = await api(faucetApi, {action: 'withdraw', token: $('cash-token').value.trim(), address});
      if (result.redemption.state !== 'submitted') throw new Error('Payment needs operator review.');
      status('cash-status', `Transaction submitted: ${result.redemption.txid}. Wait for network confirmation.`);
      if (!$('exchange-address').value) $('exchange-address').value = address;
      if (!$('pay-address').value) $('pay-address').value = address;
    } catch (error) { status('cash-status', error.message, true); }
    finally { $('quote').disabled = false; }
  });
  function renderSlot(kind, result) {
    const box = $(kind + '-slot');
    box.replaceChildren(); box.hidden = false;
    const heading = document.createElement('h2');
    heading.textContent = result.status === 'done' ? 'Exchange completed' : 'Your request'; box.append(heading);
    const fields = {Reference: result.slot_id, Status: result.status, Record: result.name};
    if (result.gen_address) fields['Send NCH to'] = result.gen_address;
    if (result.hours != null) fields.NCH = result.hours;
    if (result.recieve != null) fields['Receive NESS'] = result.recieve;
    const list = document.createElement('dl');
    for (const [label, value] of Object.entries(fields)) {
      if (value == null) continue;
      const term = document.createElement('dt'), detail = document.createElement('dd');
      term.textContent = label; detail.textContent = String(value); list.append(term, detail);
    }
    box.append(list);
    if (result.status === 'generated' || result.status === 'updated') {
      const note = document.createElement('p'); note.textContent = 'Pay with one of the options below, then check payment.'; box.append(note);
      for (const [currency, info] of Object.entries(result.payments || {})) {
        const item = document.createElement('div'); item.className = 'payment';
        const text = document.createElement('strong'); text.textContent = `${info.min_sum} ${currency}`;
        const address = document.createElement('code'); address.textContent = info.addr;
        item.append(text, address);
        if (currency === 'NCH') { const dust = document.createElement('p'); dust.className = 'small'; dust.textContent = 'Send exactly 0.000001 NESS with your NCH to this recipient.'; item.append(dust); }
        box.append(item);
      }
    }
    if (result.error) { const error = document.createElement('p'); error.className = 'error'; error.textContent = result.error; box.append(error); }
    const refresh = document.createElement('button'); refresh.className = 'secondary'; refresh.textContent = 'Check payment / refresh';
    refresh.addEventListener('click', async () => {
      refresh.disabled = true;
      try { renderSlot(kind, await api(exchangeApi, {action: 'status', kind, slot_id: result.slot_id, process: true})); }
      catch (error) { status(kind + '-status', error.message, true); refresh.disabled = false; }
    }); box.append(refresh);
    try { sessionStorage.setItem('privateness-' + kind + '-slot', result.slot_id); } catch (_) {}
  }
  for (const kind of ['exchange', 'nvs']) {
    $(kind + '-form').addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.target.querySelector('button[type=submit]'); button.disabled = true;
      const data = Object.fromEntries(new FormData(event.target));
      status(kind + '-status', 'Creating your request…');
      try { renderSlot(kind, await api(exchangeApi, {action: 'create', kind, ...data})); status(kind + '-status', 'Request ready. Keep the reference to check it later.'); }
      catch (error) { status(kind + '-status', error.message, true); }
      finally { button.disabled = false; }
    });
    let saved;
    try { saved = sessionStorage.getItem('privateness-' + kind + '-slot'); } catch (_) {}
    if (saved) api(exchangeApi, {action: 'status', kind, slot_id: saved, process: false}).then(result => renderSlot(kind, result)).catch(error => status(kind + '-status', error.message, true));
  }
})();
