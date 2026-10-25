# omnibus/purolator

Purolator for [omniscient/omnibus](https://github.com/glitchr-studio/omnibus): estimates,
shipments with their documents, tracking, locations and voids, through Purolator's E-Ship web
services (SOAP 1.2, HTTP basic authentication with the API key). The envelopes are written and read
as XML over plain HTTP (`ext-soap` is not needed). The gateway calls:

| Operation | Service (version) | Path | Request |
|---|---|---|---|
| rate | Estimating (2.2) | `Estimating/EstimatingService.asmx` | `GetFullEstimate` |
| ship | Shipping (2.2) | `Shipping/ShippingService.asmx` | `CreateShipment` |
| ship (documents) | ShippingDocuments (1.3) | `ShippingDocuments/ShippingDocumentsService.asmx` | `GetDocuments` |
| cancel | Shipping (2.2) | `Shipping/ShippingService.asmx` | `VoidShipment` |
| track | Tracking (1.2) | `Tracking/TrackingService.asmx` | `TrackPackagesByPin` |
| pickup points | Locator (1.0) | `Locator/LocatorService.asmx` | `GetLocationsByPostalCode` |

under `https://webservices.purolator.com/EWS/V2` (production) or
`https://devwebservices.purolator.com/EWS/V2` (development, `sandbox: true`). Each call has the
header `SOAPAction: http://purolator.com/pws/service/v2/<Request>`, a request context with the
service's version, the language, the group id `omnibus` and a random request reference, and a
20-second timeout.

## Installation

```sh
composer require omnibus/purolator
```

Requires PHP 8.2, `omniscient/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- An [E-Ship Web Services](https://eship.purolator.com) registration gives a development **key**
  and **password**; production keys follow certification.
- The shop's Purolator **account number**: it is the registered and the billing account of every
  shipment (`PaymentType Sender`).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `key` | string | - | yes | the development or production key (basic authentication user) |
| `password` | string | - | yes | its password |
| `account_number` | string | - | yes | the registered and billing account |
| `sandbox` | bool | `false` | no | `true`: the development web services |
| `rates` | list | - | no | prices from configuration instead of Estimating ([the rates option](https://github.com/glitchr-studio/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, Estimating is not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `sender_province` | rate, ship | - | the sender's two-letter province or state |
| `recipient_province` | rate, ship | - | the recipient's two-letter province or state |
| `pickup_type` | rate, ship | `DropOff` | `DropOff` or `PreScheduled` |
| `description` | rate, ship | `Merchandise` | the customs description, outside Canada |
| `label_format` | ship | `PDF` | `PDF` (regular printer) or `ZPL` (thermal printer) |

The address model has no province: it comes from the two options.

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (Estimating), or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | yes (shipping centres, drop boxes, agents) |
| `GetSlip` | no |
| `Cancel` | yes (void) |
| `Paperless` | no |

## Behaviour

**The shipment.** Estimating and Shipping take the same `Shipment` element:

- each address: the name and company (30 characters each); the first street line split on its
  leading number (`100 Queen St W` gives street number `100` and street name `Queen St W`; a line
  without a leading number is all street name), a second line, city, province option, country,
  postcode in capitals without spaces; the phone's digits (`0000000000` when there is none), its
  last ten split into a three-digit area code and a seven-digit number, country code `1` for
  Canada and the US, `0` elsewhere;
- the ship date `Shipment::$shippingDate`, else today;
- the service `Shipment::$service`, else `PurolatorExpress` in Canada and
  `PurolatorExpressInternational` elsewhere;
- the total weight and each piece's weight in kilograms with one decimal (at least 0.1), each
  piece's dimensions in centimetres when all three are given;
- outside Canada: not documents only, one content line (the `description`, the sender's country
  as country of manufacture, the first parcel's `value` or 1.00, quantity 1), the recipient as
  buyer, duties billed to the receiver in the first parcel's currency, a permanent export;
- payment by the sender on the account, the `pickup_type`, the reference (30 characters) as
  `Reference1`.

**Services.** Labels come from `Omnibus\Purolator\Mapping::SERVICES`:

| ServiceID | Label |
|---|---|
| `PurolatorExpress` | Purolator Express |
| `PurolatorExpress9AM` | Purolator Express 9AM |
| `PurolatorExpress10:30AM` | Purolator Express 10:30AM |
| `PurolatorExpress12PM` | Purolator Express 12PM |
| `PurolatorGround` | Purolator Ground |
| `PurolatorExpressU.S.` | Purolator Express U.S. |
| `PurolatorGroundU.S.` | Purolator Ground U.S. |
| `PurolatorExpressInternational` | Purolator Express International |

Another ServiceID is its own label.

**Rate.** `GetFullEstimate` for the shipment above with alternative services shown: every
estimate is a rate, its `TotalPrice` in `CAD`, `days` its `EstimatedTransitDays`. Rates come
cheapest first; `toPickupPoint` is `false`.

**Ship.** `CreateShipment` with printer type `Thermal` for `ZPL`, else `Regular`. The tracking
number is the `ShipmentPIN`. Then `GetDocuments`, synchronous, output `ZPL` or `PDF`, for the
document type `DomesticBillOfLadingThermal` (asked for whatever the format and the destination):
the first document's URL is the label's `url`. No document comes inline: `content` is `null`. When
`GetDocuments` fails, the booking still succeeds without a URL (a comment in `ShippingAction` says
`GetSlip` asks again; `GetSlip` is not supported). The format is `application/zpl` or
`application/pdf`; `trackingUrl` is `https://www.purolator.com/en/shipping/tracker?pin=<pin>`. A
response without a PIN is a `CarrierException` (`Purolator booked no shipment.`). `pickupPoint` is
not sent.

**Track.** `TrackPackagesByPin` in French when the locale starts with `fr`, else English. The first
package's scans: date and time (PHP's default time zone), description, the depot's name as
location, the scan type as `code`; sorted oldest first. The status is the latest scan's, mapped
from its scan type:

| Scan type | `TrackingStatus` |
|---|---|
| `Delivery`, `Delivered` | `DELIVERED` |
| `OnDelivery`, `OutForDelivery` | `OUT_FOR_DELIVERY` |
| `Pickup`, `Depot`, `ProofOfPickUp`, `InTransit`, `Other` | `IN_TRANSIT`, or `AVAILABLE_FOR_PICKUP` when the description contains `available for pickup` |
| `Undeliverable`, `Exception`, `Undelivered` | `EXCEPTION` |
| `Returned`, `ReturnToSender` | `RETURNED` |
| `ShipmentCreated`, `Shipping` | `PENDING` |
| anything else | `UNKNOWN` |

The scan types are compared in capitals, whatever their case.

**Pickup points.** `GetLocationsByPostalCode` for the address's postcode (the rest of the address
is not sent), the location types `ShippingCentre`, `DropBox` and `Agent`, within 25 km, at most
`min(50, limit)`. Each location: its `ID`, its name (else its type), street number and name as one
street line, postcode, city, country (`CA` when missing), coordinates, the distance converted from
kilometres to metres. Opening hours are read from `HoursOfOperation`: the day's English name as ISO
weekday, `OpenTime` and `CloseTime` as Purolator writes them. The locations are kept in
Purolator's order and cut to `limit`.

**Cancel.** `VoidShipment` for the PIN: `true` when Purolator answers `ShipmentVoided` true,
`false` otherwise.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Purolator\PurolatorGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$purolator = (new PurolatorGatewayFactory(HttpClient::create()))->create([
    'key' => getenv('PUROLATOR_KEY'),
    'password' => getenv('PUROLATOR_PASSWORD'),
    'account_number' => getenv('PUROLATOR_ACCOUNT'),
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA', phone: '416-555-9876'),
    new Address('Ada Lovelace', ['1 Wellington St'], 'K1A 0A9', 'Ottawa', 'CA', phone: '613-555-0100'),
    [new Parcel(2000, 30, 20, 10)],
    service: 'PurolatorGround',
    reference: 'CMD-1042',
    options: ['sender_province' => 'ON', 'recipient_province' => 'ON', 'pickup_type' => 'DropOff'],
);

foreach ($purolator->rate($shipment) as $rate) {
    printf("%-18s %6.2f %s, %s days\n", $rate->service, $rate->amount / 100, $rate->currency, $rate->days ?? '?');
}

$label = $purolator->ship($shipment);
echo $label->trackingNumber, ' ', $label->url, "\n";

$tracking = $purolator->track($label->trackingNumber, 'en');
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";

foreach ($purolator->pickupPoints($shipment->recipient, 3) as $point) {
    printf("%s %s %d m\n", $point->id, $point->name, $point->distance);
}

var_dump($purolator->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        purolator:
            factory: purolator
            options:
                key: '%env(PUROLATOR_KEY)%'
                password: '%env(PUROLATOR_PASSWORD)%'
                account_number: '%env(PUROLATOR_ACCOUNT)%'
                sandbox: '%env(bool:PUROLATOR_SANDBOX)%'
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `purolator`:

- an error in the answer's `ResponseInformation`: its first error's `Description`, its `Code` in
  `carrierCode`;
- a SOAP fault, an HTTP status of 400 or more, or an answer without a body element: the fault's
  reason text (else `faultstring`, else `HTTP <status>`);
- a body that is not XML: `Purolator answered HTTP <status> with a body that is not SOAP.`;
- a network error: `Purolator request failed: ...`;
- `Purolator booked no shipment.` when no PIN comes back.

A failed `GetDocuments` after the shipment is created is not raised (above). A missing `key`,
`password` or `account_number` is an `InvalidConfigException` when the gateway is built;
`GetSlip` and `Paperless` raise `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/PurolatorGatewayTest.php` (estimates cheapest first with their transit
days, the street number apart from the street name, the phone split, the account; a shipment
created with a regular printer and its document's link; tracking with its location, locations with
their address, distance and Monday hours; the void; an error in the answer raised with its code)
and `Tests/PurolatorContractTest.php`, the family's conformance suite on recorded answers. The
example above was run in the core's harness on those recorded answers.

Not verified: the README says the package is built from Purolator's published API documentation
and was not yet run against the development environment; that needs the credentials above. The
document type asked for (`DomesticBillOfLadingThermal`, for every shipment) and the format of the
Locator's opening times are unchecked against real answers.

## Responsibility

An independent, unofficial client, not affiliated with Purolator Inc. Whoever runs it complies
with Purolator's terms ([E-Ship Resource Centre
Agreement](https://eship.purolator.com/SITE/Files/PCL_Eship_RC_Agmt_v4.pdf)): see the README's
Responsibility section.
