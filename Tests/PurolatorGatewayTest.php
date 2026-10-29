<?php

namespace Omnibus\Purolator\Tests;

use Omnibus\Exception\CarrierException;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use Omnibus\Purolator\PurolatorGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PurolatorGatewayTest extends TestCase
{
    private array $calls = [];

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['123 Rue Sainte-Catherine'], 'H2X 1K4', 'Montréal', 'CA', phone: '514 555 1234'), new Address('Alex Martin', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA', phone: '4165559876'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_province' => 'QC', 'recipient_province' => 'ON']);
    }

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://devwebservices.purolator.com/EWS/V2/', $url);
            $body = (string) $options['body'];
            $this->calls[] = [$url, $body];
            $wrap = static fn (string $inner) => new MockResponse('<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'.$inner.'</s:Body></s:Envelope>', ['response_headers' => ['content-type' => 'application/soap+xml']]);

            return match (true) {
                str_contains($body, 'GetFullEstimateRequest') => $wrap('<GetFullEstimateResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation><ShipmentEstimates><ShipmentEstimate><ServiceID>PurolatorExpress</ServiceID><TotalPrice>24.95</TotalPrice><EstimatedTransitDays>1</EstimatedTransitDays></ShipmentEstimate><ShipmentEstimate><ServiceID>PurolatorGround</ServiceID><TotalPrice>15.30</TotalPrice><EstimatedTransitDays>2</EstimatedTransitDays></ShipmentEstimate></ShipmentEstimates></GetFullEstimateResponse>'),
                str_contains($body, 'CreateShipmentRequest') => $wrap('<CreateShipmentResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation><ShipmentPIN><Value>329014521622</Value></ShipmentPIN><PiecePINs><PIN><Value>329014521622</Value></PIN></PiecePINs></CreateShipmentResponse>'),
                str_contains($body, 'GetDocumentsRequest') => $wrap('<GetDocumentsResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation><Documents><Document><PIN><Value>329014521622</Value></PIN><DocumentDetails><DocumentDetail><DocumentType>DomesticBillOfLadingThermal</DocumentType><URL>https://devwebservices.purolator.com/EWS/Documents/329014521622.pdf</URL></DocumentDetail></DocumentDetails></Document></Documents></GetDocumentsResponse>'),
                str_contains($body, 'TrackPackagesByPinRequest') => $wrap('<TrackPackagesByPinResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation><TrackingInformationList><TrackingInformation><PIN><Value>329014521622</Value></PIN><Scans><Scan><ScanType>Delivery</ScanType><Depot><Name>TORONTO</Name></Depot><ScanDate>2026-10-02</ScanDate><ScanTime>101500</ScanTime><Description>Shipment delivered to RECEPTION</Description></Scan><Scan><ScanType>Pickup</ScanType><Depot><Name>MONTREAL</Name></Depot><ScanDate>2026-10-01</ScanDate><ScanTime>160000</ScanTime><Description>Picked up by Purolator</Description></Scan></Scans></TrackingInformation></TrackingInformationList></TrackPackagesByPinResponse>'),
                str_contains($body, 'GetLocationsByPostalCodeRequest') => $wrap('<GetLocationsByPostalCodeResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation><Locations><Location><ID>1234</ID><Name>PUROLATOR SHIPPING CENTRE</Name><LocationType>ShippingCentre</LocationType><Address><StreetNumber>100</StreetNumber><StreetName>FRONT ST W</StreetName><City>TORONTO</City><Province>ON</Province><Country>CA</Country><PostalCode>M5J1E3</PostalCode></Address><Latitude>43.6453</Latitude><Longitude>-79.3807</Longitude><Distance>1.2</Distance><HoursOfOperation><Hours><DayOfWeek>Monday</DayOfWeek><OpenTime>08:00</OpenTime><CloseTime>18:00</CloseTime></Hours></HoursOfOperation></Location></Locations></GetLocationsByPostalCodeResponse>'),
                str_contains($body, 'VoidShipmentRequest') => $wrap('<VoidShipmentResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation><ShipmentVoided>true</ShipmentVoided></VoidShipmentResponse>'),
                default => $wrap('<GetFullEstimateResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors><Error><Code>1000003</Code><Description>Unknown request</Description></Error></Errors></ResponseInformation></GetFullEstimateResponse>'),
            };
        });

        return (new PurolatorGatewayFactory($http))->create(['key' => 'devkey', 'password' => 'pass', 'account_number' => '9999999999', 'sandbox' => true]);
    }

    public function testEstimatesComeCheapestFirst(): void
    {
        $rates = $this->gateway()->rate(self::shipment());
        self::assertSame(['PurolatorGround', 'PurolatorExpress'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(1530, $rates[0]->amount);
        self::assertSame(1, $rates[1]->days);
        self::assertStringContainsString('Estimating/EstimatingService.asmx', $this->calls[0][0]);
        self::assertStringContainsString('<v2:StreetNumber>100</v2:StreetNumber><v2:StreetName>Queen St W</v2:StreetName>', $this->calls[0][1]);
        self::assertStringContainsString('<v2:AreaCode>416</v2:AreaCode><v2:Phone>5559876</v2:Phone>', $this->calls[0][1]);
        self::assertStringContainsString('<v2:RegisteredAccountNumber>9999999999</v2:RegisteredAccountNumber>', $this->calls[0][1]);
    }

    public function testAShipmentIsCreatedAndItsDocumentLinked(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('329014521622', $label->trackingNumber);
        self::assertSame('https://devwebservices.purolator.com/EWS/Documents/329014521622.pdf', $label->url);
        self::assertStringContainsString('<v2:PrinterType>Regular</v2:PrinterType>', $this->calls[0][1]);
        self::assertStringContainsString('ShippingDocuments', $this->calls[1][0]);
    }

    /**
     * E-Ship's Shipping Documents service: DomesticBillOfLading within Canada,
     * InternationalBillOfLading to the United States and abroad, the Thermal variants for a
     * thermal (ZPL) printer.
     */
    public function testTheDocumentTypeFollowsTheOutputAndTheDestination(): void
    {
        $s = self::shipment();
        $this->gateway()->ship($s);
        self::assertStringContainsString('<v2:DocumentType>DomesticBillOfLading</v2:DocumentType>', $this->calls[1][1]);
        $this->calls = [];
        $this->gateway()->ship(new Shipment($s->sender, $s->recipient, $s->parcels, reference: 'ORDER-1042', options: $s->options + ['label_format' => 'ZPL']));
        self::assertStringContainsString('<v2:DocumentType>DomesticBillOfLadingThermal</v2:DocumentType>', $this->calls[1][1]);
        $this->calls = [];
        $this->gateway()->ship(new Shipment($s->sender, new Address('Alex Martin', ['350 5th Ave'], '10118', 'New York', 'US', phone: '2125550000'), $s->parcels, reference: 'ORDER-1042', options: ['sender_province' => 'QC', 'recipient_province' => 'NY']));
        self::assertStringContainsString('<v2:DocumentType>InternationalBillOfLading</v2:DocumentType>', $this->calls[1][1]);
    }

    public function testTrackingLocationsAndVoid(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('329014521622');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame('Picked up by Purolator', $tracking->events[0]->description);
        self::assertSame('TORONTO', $tracking->latest()->location);

        $points = $gateway->pickupPoints(self::shipment()->recipient);
        self::assertSame('1234', $points[0]->id);
        self::assertSame('100 FRONT ST W', $points[0]->address->line(0));
        self::assertSame(1200, $points[0]->distance);
        self::assertSame([['08:00', '18:00']], $points[0]->openingHours[1]);

        self::assertTrue($gateway->cancel('329014521622'));
    }

    public function testAnErrorInTheResponseIsRaised(): void
    {
        $http = new MockHttpClient(new MockResponse('<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><GetFullEstimateResponse xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors><Error><Code>1100500</Code><Description>Invalid postal code</Description></Error></Errors></ResponseInformation></GetFullEstimateResponse></s:Body></s:Envelope>'));
        $gateway = (new PurolatorGatewayFactory($http))->create(['key' => 'k', 'password' => 'p', 'account_number' => '1', 'sandbox' => true]);
        try {
            $gateway->rate(self::shipment());
            self::fail('the error is raised');
        } catch (CarrierException $e) {
            self::assertSame('1100500', $e->carrierCode);
            self::assertStringContainsString('Invalid postal code', $e->getMessage());
        }
    }
}
