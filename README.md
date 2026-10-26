# omnibus/purolator

Purolator for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): estimates, shipments
with their documents, tracking, locations and voids - the E-Ship web services (SOAP 1.2, basic
auth with the API key).

```php
$gateway = (new PurolatorGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        purolator:
            factory: purolator
            options:
                key: '%env(PUROLATOR_KEY)%'            # development or production key
                password: '%env(PUROLATOR_PASSWORD)%'
                account_number: '%env(PUROLATOR_ACCOUNT)%'
                sandbox: true
                rates: [...]                           # optional: configured prices instead of Estimating
```

The service is Purolator's ServiceID (PurolatorExpress, PurolatorGround, PurolatorExpress9AM,
PurolatorExpressU.S., PurolatorExpressInternational...). Shipment options: `sender_province` and
`recipient_province` (two letters), `pickup_type` (DropOff, PreScheduled), `label_format` (PDF,
ZPL for thermal), `description` (customs). Purolator wants the street number apart from the
street name: the first line is split on its leading number.

Credentials: an [E-Ship Web Services](https://eship.purolator.com) registration gives a
development key and password; production keys follow certification; plus your account number.

Built from Purolator's published API documentation and tested on recorded answers; not yet run
against the development environment: that needs the credentials above.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Purolator Inc. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Purolator's terms ([E-Ship
Resource Centre Agreement](https://eship.purolator.com/SITE/Files/PCL_Eship_RC_Agmt_v4.pdf)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
