# NVL payments events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Host order reference, payment attempt/state and canonical Stripe references only. No customer data, raw provider payload, card details or payment exception object.

PaymentStateSyncer emits only changed financial state/facts; ResolvePaymentExceptionAction publishes the successful explicit resolution. Matching webhook/reconcile observations retain native state guards.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [PaymentStateChanged](#paymentstatechanged) | 1 | Order payment financial facts changed. |

### PaymentStateChanged

`Nvl\Payments\Events\PaymentStateChanged` · [source](../src/Events/PaymentStateChanged.php) · event schema `1`.

Order payment financial facts changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$orderReference` | `string` | public | `required` | — |
| `$attemptId` | `string` | public | `required` | — |
| `$oldState` | `string` | public | `required` | — |
| `$newState` | `string` | public | `required` | — |
| `$paymentIntentId` | `?string` | public | `required` | — |
| `$chargeId` | `?string` | public | `required` | — |
| `$checkoutSessionId` | `?string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$orderReference` | `string` | — |
| `$attemptId` | `string` | — |
| `$oldState` | `string` | — |
| `$newState` | `string` | — |
| `$paymentIntentId` | `?string` | — |
| `$chargeId` | `?string` | — |
| `$checkoutSessionId` | `?string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/ResolvePaymentExceptionAction.php](../src/Actions/ResolvePaymentExceptionAction.php) | `$connection = $attempt->getConnection()` |
| [Services/PaymentStateSyncer.php](../src/Services/PaymentStateSyncer.php) | `$connection = (new PaymentAttempt)->getConnection()` |

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

