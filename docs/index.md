# omnifood/thefork

TheFork (LaFourchette) for [omnisense/omnifood](https://github.com/glitchr-omnisense/omnifood): a
restaurant's reservations read, recorded, changed and cancelled, its time slots opened or closed
to online booking, and its webhooks checked - through TheFork's B2B API (TheFork Manager's),
documented at https://docs.thefork.io/B2B-API/introduction and
https://api.thefork.io/manager/openapi.json. The calls go to `https://api.thefork.io/manager`
with a token from TheFork's Auth0 at `https://auth.thefork.io/oauth/token` (audience
`https://api.thefork.io`).

| Operation | TheFork |
|---|---|
| `reservations($from, $to)` | `GET /v1/reservations?restaurantUuid&startDate&endDate&filterBy=mealDate` (ids, pages), then each |
| `reservation($id)` | `GET /v1/reservations/{id}`, and `GET /v1/customers/{customerUuid}` |
| `createReservation($r)` | `POST /v1/restaurants/{restaurant_id}/reservations` |
| `updateReservation($r)` | `PATCH /v1/reservations/{id}` |
| `cancelReservation($id)` | `PATCH /v1/reservations/{id}/cancel` |
| `setStatus($id, CANCELLED)` | the same cancellation |
| `pushAvailability($slots)` | `PUT /v1/restaurants/{restaurant_id}/availabilities/override`, one per slot |
| `notify($body, $headers)` | the webhook `{entityType, eventType, uuid, ...}`, its URL token checked |
| `token()`, `refresh()` | `POST https://auth.thefork.io/oauth/token`, client credentials |

## Installation

```sh
composer require omnifood/thefork
```

Requires PHP 8.2, `omnisense/omnifood` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnifood\Bridge\Symfony\OmnifoodBundle`, the factory is registered as soon as
the package is installed.

## Credentials

- An e-mail to **integrations@thefork.com** with the company and the use: TheFork sends a
  **client id and secret** through a safe channel, with the scopes it grants
  (`tfm-restaurant:reservations`, `:customers`, `:availabilities`, `:override-availabilities`...).
- The restaurant's **UUID** at TheFork (and its group's), which the public documentation does not
  say where to find: ask TheFork with the keys.
- The **webhook URL** with a token of the site's choosing
  (`https://site.example/webhooks/thefork?token=...`), sent to TheFork, who configures it. The
  restaurant group may have to accept TheFork's ERB in TheFork Manager first.

TheFork's B2B API knows client credentials only: there is no authorization by the restaurant, and
no `OAuthInterface`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | for every call | sent by TheFork |
| `client_secret` | string | - | for every call | |
| `restaurant_id` | string | - | for `reservations()`, `createReservation()`, `pushAvailability()` | the restaurant's UUID |
| `webhook_token` | string | - | for `notify()` | the token put in the webhook URL |
| `access_token` | string | - | no | a token kept by the site |
| `token_expires_at` | string (a date, ATOM) | - | no | when that token dies; a date PHP cannot read is an `InvalidConfigException` |
| `locale` | string | `fr_FR` | no | a guest's, when the reservation does not say |
| `civility` | string | `mx` | no | a guest's (TheFork requires one), when `raw['civility']` does not say |
| `with_customers` | bool | `true` | no | read each reservation's customer (name, phone, allergies): one call more each |
| `base_uri` | string | `https://api.thefork.io/manager` | no | |
| `token_url` | string | `https://auth.thefork.io/oauth/token` | no | |

No option is checked when the platform is built; a call that needs one and finds it empty throws
`InvalidConfigException` (`The "thefork" platform needs: client_id, client_secret.`,
`... needs: restaurant_id.`, `... needs: webhook_token, to check its webhooks.`).

## Capabilities

| Interface | Supported |
|---|---|
| `ReservationsInterface` | `reservations()`, `reservation()`, `createReservation()`, `updateReservation()` (date, party size, staff note), `cancelReservation()`, `pushAvailability()`; `setStatus()` only for `CANCELLED` |
| `NotifiableInterface` | yes |
| `Auth\RefreshableInterface` | yes |
| Orders, menu, store | no |

`capabilities()`: `availabilityPush` true, `deposits` false, everything else at its default.

## Behaviour

**Token.** `POST` of a form with the audience, `grant_type=client_credentials`, the client id and
secret; it lives about 2 h 23, and is kept until 60 seconds before it dies. TheFork asks that no
new one be requested before: a site with many processes keeps it and gives it back as
`access_token` / `token_expires_at`.

**Reading.** `reservations($from, $to)` lists the ids by meal date, from `$from`'s UTC date to
`$to`'s (inclusive), 100 a page while the `totalCount` is not reached, then reads each (and its
customer when `with_customers` is on); those whose date is outside `$from`-`$to` are left out, the
others sorted oldest first. The mapping:

- `date` `mealDate`, `covers` `partySize`, `reference` `reservationUuid`, `notes`
  `customerNote`, `source` `reservationChannel`, `createdAt`, `restaurant` `restaurantUuid`,
  `channel` TheFork;
- the guest from the customer: first and last names, e-mail, phone, locale, `customerUuid` as its
  id; `allergies` the customer's `allergiesAndIntolerances`, joined;
- `status`: `RECORDED` is `CONFIRMED`, or by `mealStatus` `ARRIVED` (`PARTIALLY_ARRIVED`,
  `ARRIVED`), `SEATED` (`SEATED`, `BILL`), `FINISHED` (`LEFT`); `REQUESTED`, `CANCELED`
  (`CANCELLED`), `NO_SHOW`, `REFUSED` as they are; anything else `UNKNOWN`;
- `table`, `duration` and `deposit` are `null`: not in the public API.

**Recording a reservation.** `createReservation()` sends `mealDate` (ATOM), `partySize`,
`customerNote` (the notes), `restaurantNote` (`raw['restaurantNote']`), `offerUuid`
(`raw['offerUuid']`: booked on an offer, else on normal stock) and the customer: e-mail, first and
last names, phone, the locale as `ll` or `ll_CC` (`fr-fr` becomes `fr_FR`; the guest's, else the
`locale` option), the civility (`raw['civility']`, else the `civility` option). TheFork requires
the e-mail, the names, a locale and a civility, and the phone in E.164: `+33 6 12 34 56 78` and
`0033 6 12 34 56 78` as they say (`+33612345678`, a `(0)` dropped); a national number
(`06 12 34 56 78`) in the country of the guest's locale (`fr_FR`), else of the `locale` option,
its trunk 0 dropped (kept in Italy, San Marino and the Vatican): `+33612345678`. A national
number when neither locale names a country TheFork serves is a `\InvalidArgumentException`,
nothing sent.

**Changing and cancelling.** `updateReservation()` sends the date and the party size, and the
staff's note when `raw` has a `restaurantNote` key (`null` clears it); the guest and the notes are
not writable, and are not sent. A reservation without a reference is a `\InvalidArgumentException`.
`cancelReservation()` cancels as the restaurant; the reason is not sent (TheFork records the
restaurant's as "other"). `setStatus()` cancels for `CANCELLED`; for any other status it throws
`NotSupportedException`: TheFork's public API reads the service's statuses and publishes no call
to set them.

**Online slots.** `pushAvailability()` sends one override per `Slot`: its date, `startTime`,
`endTime` (when it has an end) in `H:i`, and `isOpen` (covers above 0). TheFork opens or closes
slots for online booking; it takes no number of covers. An entry that is not a `Slot` is a
`\InvalidArgumentException`.

**Webhooks.** TheFork authenticates its webhook by the token put in the URL it was given, and by
nothing else: the controller passes that query parameter among the headers as `token`, compared in
constant time. The body is `{entityType, eventType, uuid, groupUuid, restaurantUuid}`: `event` is
`eventType`, `subject` reservation when `entityType` is `reservation`, `reference` `uuid`, `store`
`restaurantUuid`, `id` `null` (TheFork gives none). The webhook carries no data:
`reservation($notification->reference)` reads it. Answer 200 `{"data": {}}` within a few seconds.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifood\Model\Guest;
use Omnifood\Model\Reservation;
use Omnifood\Model\Slot;
use Omnifood\TheFork\TheForkPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$thefork = (new TheForkPlatformFactory(HttpClient::create()))->create([
    'client_id' => getenv('THEFORK_CLIENT_ID') ?: null,
    'client_secret' => getenv('THEFORK_CLIENT_SECRET') ?: null,
    'restaurant_id' => getenv('THEFORK_RESTAURANT_ID') ?: null,
    'webhook_token' => getenv('THEFORK_WEBHOOK_TOKEN') ?: null,
]);

// The day's book
foreach ($thefork->reservations(new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow')) as $r) {
    printf("%s  %d  %s  %s\n", $r->date->format('H:i'), $r->covers, $r->guest->name(), $r->allergies);
}

// A reservation taken by phone
$recorded = $thefork->createReservation(new Reservation(
    new \DateTimeImmutable('2026-10-12 19:30'), 4,
    new Guest('Jo', 'Martin', 'jo@example.com', '+33 6 12 34 56 78', 'fr'),
    notes: 'A high chair',
));
$thefork->updateReservation($recorded->with(covers: 5));

// Closed online on Saturday evening
$thefork->pushAvailability([
    new Slot(new \DateTimeImmutable('2026-10-10 19:00'), 0, new \DateTimeImmutable('2026-10-10 21:30')),
]);
```

The webhook controller, in Symfony:

```php
try {
    $notification = $thefork->notify($request->getContent(), $request->headers->all() + ['token' => (string) $request->query->get('token')]);
} catch (InvalidSignatureException) {
    return new JsonResponse(['error' => 'unauthorized'], 401);
}
if (NotificationSubject::RESERVATION === $notification->subject) {
    $bus->dispatch(new SyncReservation('thefork', $notification->reference));   // the application's message
}

return new JsonResponse(['data' => new \stdClass()]);
```

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:
        thefork:
            factory: thefork
            options:
                client_id: '%env(default::THEFORK_CLIENT_ID)%'
                client_secret: '%env(default::THEFORK_CLIENT_SECRET)%'
                restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%'
                webhook_token: '%env(default::THEFORK_WEBHOOK_TOKEN)%'
```

`createReservation()` was run on a recorded answer on 2026-10-26 (the phone's E.164 rules checked
since by `testANationalPhoneIsSentInE164`, on a mock); nothing was run against TheFork.

## Errors

- 401 and 403: `UnauthorizedException`; 429: `ProviderException` `Too many calls, try again
  later: ...`; any other status of 400 or more: `ProviderException`, the message from `message`
  (else `error_description`, the code, `error`, `HTTP <status>.`), `data.code` as `providerCode`
  (or the token endpoint's `error` on a 401-403).
- A network failure: `ProviderException` `TheFork could not be reached: ...`.
- A 2xx answer whose body is not JSON (a proxy's or a maintenance page): `ProviderException`
  `An answer that is not JSON (HTTP 200).`, with the status.
- A token answer without `access_token`: `ProviderException` `No token in TheFork's answer.`
- `setStatus()` other than `CANCELLED`: `NotSupportedException`. A webhook whose token does not
  match, or whose body is not JSON: `InvalidSignatureException`.

## Verified, and not

`Tests/TheForkPlatformTest.php` checks, on recorded answers written from TheFork's OpenAPI: the
reservations of a period page by page with their guests, the statuses and the service as it goes,
a reservation taken by phone recorded, a reservation updated and cancelled, the service's statuses
not writable, slots opened or closed online, a webhook saying what to read again, the token kept
until it dies and given back, the errors, and what works without keys. Green in the family's
harness on 2026-10-26. Not run against TheFork's API.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by TheFork. Whoever runs it complies with TheFork's terms (those it gives with the client
id and secret; [docs.thefork.io](https://docs.thefork.io/)); see the README's Responsibility
section.
