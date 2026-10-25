<?php

namespace Omnibus\Purolator\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Purolator\PurolatorGatewayFactory;
use Omnibus\Testing\GatewayContractTests;
use Omnibus\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Purolator's recorded answers (E-Ship web services, development). */
final class PurolatorContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new PurolatorGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['key' => 'devkey', 'password' => 'pass', 'account_number' => '9999999999', 'sandbox' => true];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['123 Rue Sainte-Catherine'], 'H2X 1K4', 'Montréal', 'CA', phone: '514 555 1234'), new Address('Alex Martin', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA', phone: '4165559876'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_province' => 'QC', 'recipient_province' => 'ON']);
    }

    protected function contractTrackingNumber(): string
    {
        return '329014521622';
    }

    protected function recordedAnswers(): array
    {
        $soap = static fn (string $operation, string $inner) => RecordedHttpClient::answer('<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><'.$operation.'Response xmlns="http://purolator.com/pws/datatypes/v2"><ResponseInformation><Errors/></ResponseInformation>'.$inner.'</'.$operation.'Response></s:Body></s:Envelope>', 200, ['content-type' => 'application/soap+xml']);

        return [
            'body:GetFullEstimateRequest' => $soap('GetFullEstimate', '<ShipmentEstimates><ShipmentEstimate><ServiceID>PurolatorExpress</ServiceID><TotalPrice>24.95</TotalPrice><EstimatedTransitDays>1</EstimatedTransitDays></ShipmentEstimate><ShipmentEstimate><ServiceID>PurolatorGround</ServiceID><TotalPrice>15.30</TotalPrice><EstimatedTransitDays>2</EstimatedTransitDays></ShipmentEstimate></ShipmentEstimates>'),
            'body:CreateShipmentRequest' => $soap('CreateShipment', '<ShipmentPIN><Value>329014521622</Value></ShipmentPIN><PiecePINs><PIN><Value>329014521622</Value></PIN></PiecePINs>'),
            'body:GetDocumentsRequest' => $soap('GetDocuments', '<Documents><Document><PIN><Value>329014521622</Value></PIN><DocumentDetails><DocumentDetail><DocumentType>DomesticBillOfLadingThermal</DocumentType><URL>https://devwebservices.purolator.com/EWS/Documents/329014521622.pdf</URL></DocumentDetail></DocumentDetails></Document></Documents>'),
            'body:TrackPackagesByPinRequest' => $soap('TrackPackagesByPin', '<TrackingInformationList><TrackingInformation><PIN><Value>329014521622</Value></PIN><Scans><Scan><ScanType>Delivery</ScanType><Depot><Name>TORONTO</Name></Depot><ScanDate>2026-10-02</ScanDate><ScanTime>101500</ScanTime><Description>Shipment delivered to RECEPTION</Description></Scan><Scan><ScanType>Pickup</ScanType><Depot><Name>MONTREAL</Name></Depot><ScanDate>2026-10-01</ScanDate><ScanTime>160000</ScanTime><Description>Picked up by Purolator</Description></Scan></Scans></TrackingInformation></TrackingInformationList>'),
            'body:GetLocationsByPostalCodeRequest' => $soap('GetLocationsByPostalCode', '<Locations><Location><ID>1234</ID><Name>PUROLATOR SHIPPING CENTRE</Name><LocationType>ShippingCentre</LocationType><Address><StreetNumber>100</StreetNumber><StreetName>FRONT ST W</StreetName><City>TORONTO</City><Province>ON</Province><Country>CA</Country><PostalCode>M5J1E3</PostalCode></Address><Latitude>43.6453</Latitude><Longitude>-79.3807</Longitude><Distance>1.2</Distance><HoursOfOperation><Hours><DayOfWeek>Monday</DayOfWeek><OpenTime>08:00</OpenTime><CloseTime>18:00</CloseTime></Hours></HoursOfOperation></Location></Locations>'),
            'body:VoidShipmentRequest' => $soap('VoidShipment', '<ShipmentVoided>true</ShipmentVoided>'),
        ];
    }
}
