<?php

namespace Omnibus\Purolator\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Purolator\Api;
use Omnibus\Purolator\Mapping;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/** Shipping's CreateShipment, then ShippingDocuments' GetDocuments for the label's link (a thermal label with option label_format ZPL). */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $thermal = 'ZPL' === strtoupper((string) $s->option('label_format', 'PDF'));
        $data = $this->api->call(Api::SHIPPING, 'CreateShipmentRequest', Mapping::shipment($s, $this->api->accountNumber).'<v2:PrinterType>'.($thermal ? 'Thermal' : 'Regular').'</v2:PrinterType>');
        $pin = (string) ($data->ShipmentPIN->Value ?? '');
        if ('' === $pin) {
            throw new CarrierException('purolator', 'Purolator booked no shipment.');
        }
        // Purolator's document types: DomesticBillOfLading within Canada, InternationalBillOfLading
        // to the United States and abroad, each with its Thermal variant for a thermal printer.
        $type = ('CA' === strtoupper($s->sender->country) && 'CA' === strtoupper($s->recipient->country) ? 'Domestic' : 'International').'BillOfLading'.($thermal ? 'Thermal' : '');
        $url = null;
        try {
            $documents = $this->api->call(Api::SHIPPING_DOCUMENTS, 'GetDocumentsRequest', '<v2:OutputType>'.($thermal ? 'ZPL' : 'PDF').'</v2:OutputType><v2:Synchronous>true</v2:Synchronous><v2:DocumentCriterium><v2:DocumentCriteria><v2:PIN><v2:Value>'.Api::e($pin).'</v2:Value></v2:PIN><v2:DocumentTypes><v2:DocumentType>'.$type.'</v2:DocumentType></v2:DocumentTypes></v2:DocumentCriteria></v2:DocumentCriterium>');
            $url = (string) ($documents->Documents->Document[0]->DocumentDetails->DocumentDetail[0]->URL ?? '') ?: null;
        } catch (CarrierException) {
            // The documents follow a moment later: the booking stands without a URL (GetSlip is not offered).
        }
        $request->setResult(new Label('purolator', $pin, null, $thermal ? Label::ZPL : Label::PDF, $url, 'https://www.purolator.com/en/shipping/tracker?pin='.rawurlencode($pin)));
    }
}
