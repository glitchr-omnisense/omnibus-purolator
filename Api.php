<?php

namespace Omnibus\Purolator;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Purolator's E-Ship web services (SOAP 1.2, basic auth with the development or
 * production key as username): Estimating, Shipping, Tracking and Locator,
 * each its own endpoint and version.
 */
final class Api
{
    public const LIVE = 'https://webservices.purolator.com/EWS/V2';
    public const TEST = 'https://devwebservices.purolator.com/EWS/V2';
    private const NS = 'http://purolator.com/pws/datatypes/v2';

    public const ESTIMATING = ['Estimating/EstimatingService.asmx', '2.2'];
    public const SHIPPING = ['Shipping/ShippingService.asmx', '2.2'];
    public const SHIPPING_DOCUMENTS = ['ShippingDocuments/ShippingDocumentsService.asmx', '1.3'];
    public const TRACKING = ['Tracking/TrackingService.asmx', '1.2'];
    public const LOCATOR = ['Locator/LocatorService.asmx', '1.0'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $key,
        private readonly string $password,
        public readonly string $accountNumber,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    /**
     * One SOAP call: the request element's name and inner XML, back as the response element without namespaces.
     *
     * @param array{string, string} $service
     */
    public function call(array $service, string $request, string $body, string $language = 'en'): \SimpleXMLElement
    {
        [$path, $version] = $service;
        $envelope = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:v2="'.self::NS.'">'
            .'<soap:Header><v2:RequestContext><v2:Version>'.$version.'</v2:Version><v2:Language>'.$language.'</v2:Language><v2:GroupID>omnibus</v2:GroupID><v2:RequestReference>'.bin2hex(random_bytes(6)).'</v2:RequestReference></v2:RequestContext></soap:Header>'
            .'<soap:Body><v2:'.$request.'>'.$body.'</v2:'.$request.'></soap:Body></soap:Envelope>';
        try {
            $response = $this->http->request('POST', ($this->sandbox ? self::TEST : self::LIVE).'/'.$path, [
                'auth_basic' => [$this->key, $this->password],
                'headers' => ['Content-Type' => 'application/soap+xml; charset=utf-8', 'SOAPAction' => 'http://purolator.com/pws/service/v2/'.$request],
                'body' => $envelope,
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('purolator', 'Purolator request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        $xml = @simplexml_load_string(preg_replace(['/<(\/?)[\w-]+:/', '/\sxmlns(:\w+)?="[^"]*"/'], ['<$1', ''], $content));
        if (false === $xml) {
            throw Redaction::withoutArguments(new CarrierException('purolator', sprintf('Purolator answered HTTP %d with a body that is not SOAP.', $status)));
        }
        $result = $xml->Body->children()[0] ?? null;
        if ($status >= 400 || null === $result || isset($xml->Body->Fault)) {
            throw Redaction::withoutArguments(new CarrierException('purolator', (string) ($xml->Body->Fault->Reason->Text ?? $xml->Body->Fault->faultstring ?? sprintf('HTTP %d', $status))));
        }
        $error = $result->ResponseInformation->Errors->Error[0] ?? null;
        if (null !== $error) {
            throw Redaction::withoutArguments(new CarrierException('purolator', (string) $error->Description, (string) $error->Code ?: null));
        }

        return $result;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    /**
     * What a failure must not carry: the credentials, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->key, $this->password, ...$more];
    }
}
