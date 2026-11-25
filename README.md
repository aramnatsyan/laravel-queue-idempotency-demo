# Laravel queue demo: no duplicate payment effects after a crash

A small Laravel app that shows, with tests, what happens when a payment job crashes or is delivered twice.

## The problem
A queue delivers at least once. A worker can crash after the payment gateway charged the customer but before the app saved the result. The retry must not charge again.

## The design (`app/Jobs/ChargeOrder.php`)
1. **Intent row first.** `charges` has a unique `order_id` and a unique `idempotency_key` (`order-<id>`). Creating it twice is harmless: the unique constraint is the last guard against duplicates.
2. **Claim under a row lock.** A transaction does `SELECT ... FOR UPDATE`; if the status is already `charged` the job returns without doing anything. Otherwise it sets `charging` and counts the attempt.
3. **Reconcile before charging.** The job asks the gateway whether a charge with this key exists (`findByKey`). Only if not does it charge, passing the same idempotency key. This is what makes a retry after a lost response safe.
4. **Mark charged** with the gateway reference.
5. **Bounded retries.** `tries = 3` with a backoff; a job that keeps failing lands in `failed_jobs` (and should alert), and `queue:retry` can run it again once the gateway recovers.

## What the tests prove (`tests/Feature/ChargeOrderTest.php`)
| Test | Proves |
|---|---|
| crash after the gateway charged does not charge twice on retry | the gateway ledger has 1 row and the app called the charge endpoint once; the retry only looked it up |
| the same order dispatched twice charges once | duplicate delivery is harmless |
| a job that keeps failing ends in failed jobs and charges nothing | failures are visible and leave no side effect |
| a failed job can be retried after the gateway recovers | recovery needs no manual data fixing |

Check that the tests are real: remove the `findByKey` lookup in the job and the first test fails ("2 is identical to 1").

## Run it
Needs Docker only.

```bash
./setup.sh
```
It creates a Laravel skeleton in `app/` with Composer, copies the files from `overlay/` into it, and runs `php artisan test` on PHP 8.4.

## Limits (honest)
- The gateway is a fake with its own ledger table; a real provider needs its real idempotency-key header.
- Tests use SQLite in memory. `lockForUpdate` only locks on MySQL/PostgreSQL; on SQLite the unique constraints carry the guarantee. Run against MySQL before relying on the lock.
- Two workers running the same job at the same moment are not simulated; they are covered by the lock plus the idempotency key at the provider.
