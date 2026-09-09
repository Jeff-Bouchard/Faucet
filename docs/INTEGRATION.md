# Faucet and NVS exchange integration

## Deploy together

Use this Faucet branch with `Jeff-Bouchard/NVS-exchange` branch `codex/unified-faucet-portal`. The shared interface is the Faucet `web/index.php`. Both remain PHP applications; no chain VM, JavaScript server framework, or replacement token protocol is introduced.

1. Back up both code checkouts, the current configuration, the faucet signing key, and both SQLite databases. Keep the current PHP timezone: historical signatures include a formatted local timestamp.
2. Use a supported PHP 8.x runtime with sodium, PDO SQLite, cURL, SimpleXML, and the existing Emercoin dependencies. Run `php tests/run.php` in both checkouts. Deploy only each repository's `web/` as its document root.
3. Preserve the faucet `config/config.php`, `data/tokens.db`, and the referenced key file. Preserve the exchange configuration and database; the sample now documents `db.filename`. Make `data/` and the SQLite directory writable by PHP. The new `redemptions` table is created automatically alongside the existing faucet `tokens` table. No existing data is deleted.
4. Run Faucet on an internal HTTP service and NVS-exchange on another. The supplied `deploy/nginx.conf.example` exposes Faucet at `/faucet/` and retains the exchange's existing root-relative legacy routes. Set `EXCHANGE_API_PATH=/api.php` on Faucet, `FAUCET_PORTAL_URL=/faucet/` on NVS-exchange, and `COOKIE_SECURE=1` on both behind HTTPS. Make sure those variables reach the PHP worker, including PHP-FPM `env[]` entries if needed. Set a finite default_socket_timeout (for example 15 seconds) on both services.
5. Use one writable session store per service across its workers. The session names are distinct. Use a single shared SQLite file and local filesystem for each app. This implementation is intended for one host; do not place independent databases on multiple replicas. Public HTTPS is required for reliable browser clipboard access.
6. Open `/faucet/`. Check that the meme loads, tokens are absent before release, the countdown completes, copy works, and the token transfers to Cash out. Configure a disposable wallet and run a small staging payment before enabling public payouts.
7. Verify an NVS request and an exchange request against the configured Emercoin and NESS nodes. The exchange still resolves `worm:exchange:ness_exchange_v1_v2` and calls its external exchange service. Keep the existing processing job if it is used; the portal also provides an explicit Check payment action.

## User flow

Faucet shows the existing repository's Neo image with new meme captions. Start creates a server-session claim with a random identifier and a 10-second deadline; no signature is returned. Release checks that exact deadline before signing the original claim timestamp. Copy and Use for cash-out become available only after the token response arrives. The countdown pauses in the supplied interface while the tab or Faucet panel is hidden. It is a minimum server wait and a browser display policy, not proof of human attention or an ad-verification protocol.

Reloading and starting again resumes the current claim for up to 15 minutes. Release retries return the same legacy token. After release, Get another token starts another 10-second claim. Existing saved, mature time tokens can be pasted directly into Cash out; they do not have to view a new meme.

The current time-token format remains `UNIXTIME_SIGNATURE`, including its deterministic one-token-per-second behavior. Separate visitors starting in the same second may receive the same token; only one redemption succeeds. Issuing a unique token per visitor requires a versioned signed format and corresponding pricing/storage design, which this change intentionally does not introduce.

NVS and NCH exchange share the page, but the time token is not submitted as an exchange WORM token. A successful faucet submission fills empty source and destination address fields in the exchange form. The wallet must confirm the payout before the user can spend it. Both record creation and exchange activation display the existing service's payment options and statuses. Existing record edit/delete pages remain available at their legacy URLs.

## Meme placement

The placement is `templates/portal.php` (`#meme-slot`), currently using `web/assets/img/neoguns.jpeg`. Replace the image with a local, licensed asset at the same path, or update its local path and descriptive alt text. The countdown waits for a successful image load. No third-party ad scripts or trackers are included. Google-served rewarded ads currently prohibit cryptocurrency and other direct monetary rewards (https://support.google.com/admanager/answer/7496282?hl=en); do not assume this cash-out placement is eligible. The CSP currently disallows frames. A future ad integration should isolate creatives and validate rewarded-inventory rules before changing those controls; an ad callback must never replace the server deadline.

## Withdrawal behavior and recovery

`api.php` validates signature format, verifies the signature, rejects future/immature/spent tokens, and computes the existing value. A unique SQLite reservation is committed before any wallet call. It then marks the legacy token spent and submits the existing wallet transaction. A successful response records `submitted` and the transaction ID, which means broadcast accepted, not chain confirmation.

If a wallet call, process, or response fails after reservation, the token remains `pending` or `review`. Do not delete the reservation or automatically retry. Inspect `redemptions` by `token_time` (the timestamp prefix, never publish the full bearer token), compare the stored recipient and amount with wallet transactions, then reconcile the payment manually. This deliberately favors avoiding duplicate transfers over automatic recovery. It is not an exactly-once protocol spanning SQLite and the blockchain.

The configured NESS and NCH reward rates remain unchanged. The separate network rule is a fixed 0.000001 NESS dust amount sent to the recipient for an NCH transfer. The exchange instructions state this explicitly; the faucet adapter retains its pre-existing combined NESS/NCH reward output. Confirm the live node's acceptance of the configured rewards in staging.

## Scope limits

The wait does not prove ad viewability or stop users opening many sessions. Token timestamp collisions, legacy exchange editor authorization, legacy slot processing races, external WORM endpoint trust, live liquidity, and economic parameters require their own operational review. The shared portal uses CSRF protection, no-store responses, text-only rendering of service output, escaped XML attributes, and request ownership in its session. The legacy exchange pages retain their older permission model. No production wallet credentials or live infrastructure are included.

## HTTP contract

- Faucet GET `api.php`: session CSRF token and wait duration.
- Faucet POST JSON with `X-CSRF-Token`: `start` (optional `new`), `release` (`claim_id`), `quote` (`token`), `withdraw` (`token`, `address`). Early release returns 425 and `remaining_ms` without a token. Expired or foreign claims return 400.
- Exchange GET `api.php`: separate session CSRF token.
- Exchange POST: `create` with `kind=nvs` (`name`, `value`, `owner`, `days`) or `kind=exchange` (`address`, `pay_address`); `status` with `kind`, `slot_id`, optional `process=true`. Creation retries recover the same request for the same session and payload. New slot creation retains the 30-second global cooldown. Status requests require ownership in the current PHP session.

## Repeatable verification

Run `php tests/run.php` in each repository. For the cross-service HTTP checks, run `PHP_BIN=php python3 tests/integration.py /path/to/NVS-exchange` from Faucet. This harness uses Python standard-library modules, disposable repository copies, fresh signing keys, fake RPC nodes, and ephemeral local PHP HTTP workers. It does not send blockchain transactions.

The added Faucet Dockerfile serves `web/`; mount the real `config/config.php` read-only and persist `data/` when running it. The NVS image now grants the application user access to its default data directory. Container builds and production TLS routing still need validation on the deployment host.
