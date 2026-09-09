# Faucet
Crypto faucet based on time-tokens (proof of time)  

Currently supported only Privateness (NESS) crypto, but it can be easily rewriten for any other crypto.

# Install
## Requirements
 * PHP 7.3 +
 * php-sodium
 * php-sqlite3

# links
[My article on Medium](https://ness-main-dev.medium.com/proof-of-time-improved-d648515afc45)
# Shared interface and meme countdown

This branch provides a 10-second server-gated claim, same-page cash-out, and a shared NVS/NCH exchange interface. Pair it with the NVS-exchange portal branch. See [integration and deployment](docs/INTEGRATION.md). Run `php tests/run.php` before deployment.
