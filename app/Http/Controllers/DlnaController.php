<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Services\Dlna\DidlWriter;
use App\Services\Dlna\DlnaCatalogue;
use App\Services\Dlna\DlnaSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The HTTP half of the DLNA server (S-7).
 *
 * Three things a client needs: a device description telling it what this is,
 * a SOAP endpoint to browse, and a URL per item to fetch the bytes from. SSDP
 * (the discovery half) lives in the `dlna:serve` command — it needs a UDP
 * socket, which PHP-FPM cannot hold open.
 *
 * Every route here is gated by `EnsureDlnaEnabled`: switched on, and called
 * from the local network. There is no authentication because the protocol has
 * none, which is exactly why that middleware matters.
 */
class DlnaController extends Controller
{
    public function __construct(
        private DlnaCatalogue $catalogue,
        private DlnaSettings $settings,
        private DidlWriter $didl,
    ) {}

    /**
     * The device description a client fetches first.
     *
     * The UDN must be stable across restarts or every client re-adds the
     * server as a new device each time it boots, leaving a list of ghosts.
     */
    public function description(Request $request): Response
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
                .'<root xmlns="urn:schemas-upnp-org:device-1-0">'
                .'<specVersion><major>1</major><minor>0</minor></specVersion>'
                .'<device>'
                .'<deviceType>urn:schemas-upnp-org:device:MediaServer:1</deviceType>'
                .'<friendlyName>%s</friendlyName>'
                .'<manufacturer>SoundChex</manufacturer>'
                .'<modelName>SoundChex Media Server</modelName>'
                .'<UDN>uuid:%s</UDN>'
                .'<serviceList><service>'
                .'<serviceType>urn:schemas-upnp-org:service:ContentDirectory:1</serviceType>'
                .'<serviceId>urn:upnp-org:serviceId:ContentDirectory</serviceId>'
                .'<SCPDURL>/dlna/content-directory.xml</SCPDURL>'
                .'<controlURL>/dlna/control</controlURL>'
                .'<eventSubURL>/dlna/event</eventSubURL>'
                .'</service></serviceList>'
                .'</device></root>',
            htmlspecialchars($this->settings->friendlyName(), ENT_XML1, 'UTF-8'),
            $this->settings->uuid(),
        );

        return response($xml, 200, ['Content-Type' => 'text/xml; charset="utf-8"']);
    }

    /** The ContentDirectory service description. */
    public function serviceDescription(): Response
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<scpd xmlns="urn:schemas-upnp-org:service-1-0">'
            .'<specVersion><major>1</major><minor>0</minor></specVersion>'
            .'<actionList><action><name>Browse</name><argumentList>'
            .$this->argument('ObjectID', 'in', 'A_ARG_TYPE_ObjectID')
            .$this->argument('BrowseFlag', 'in', 'A_ARG_TYPE_BrowseFlag')
            .$this->argument('Filter', 'in', 'A_ARG_TYPE_Filter')
            .$this->argument('StartingIndex', 'in', 'A_ARG_TYPE_Index')
            .$this->argument('RequestedCount', 'in', 'A_ARG_TYPE_Count')
            .$this->argument('SortCriteria', 'in', 'A_ARG_TYPE_SortCriteria')
            .$this->argument('Result', 'out', 'A_ARG_TYPE_Result')
            .$this->argument('NumberReturned', 'out', 'A_ARG_TYPE_Count')
            .$this->argument('TotalMatches', 'out', 'A_ARG_TYPE_Count')
            .$this->argument('UpdateID', 'out', 'A_ARG_TYPE_UpdateID')
            .'</argumentList></action></actionList>'
            .'<serviceStateTable>'
            .$this->stateVariable('A_ARG_TYPE_ObjectID', 'string')
            .$this->stateVariable('A_ARG_TYPE_BrowseFlag', 'string')
            .$this->stateVariable('A_ARG_TYPE_Filter', 'string')
            .$this->stateVariable('A_ARG_TYPE_Index', 'ui4')
            .$this->stateVariable('A_ARG_TYPE_Count', 'ui4')
            .$this->stateVariable('A_ARG_TYPE_SortCriteria', 'string')
            .$this->stateVariable('A_ARG_TYPE_Result', 'string')
            .$this->stateVariable('A_ARG_TYPE_UpdateID', 'ui4')
            .'</serviceStateTable></scpd>';

        return response($xml, 200, ['Content-Type' => 'text/xml; charset="utf-8"']);
    }

    /**
     * The SOAP control endpoint. Only `Browse` is implemented.
     *
     * `Search` is optional in the spec and every client falls back to browsing
     * when it is absent, so answering it badly would be worse than not
     * advertising it at all.
     */
    public function control(Request $request): Response
    {
        $body = $request->getContent();

        if (! str_contains($body, 'Browse')) {
            return $this->soapFault('Optional action not implemented', 602);
        }

        $objectId = $this->soapValue($body, 'ObjectID') ?? DlnaCatalogue::ROOT;
        $start = (int) ($this->soapValue($body, 'StartingIndex') ?? 0);
        $requested = (int) ($this->soapValue($body, 'RequestedCount') ?? 0);
        $flag = $this->soapValue($body, 'BrowseFlag') ?? 'BrowseDirectChildren';

        // 0 means "everything" in the spec. Capped anyway: a client asking for
        // all 5,000 tracks at once would build a response no television can
        // parse, and most ask for pages regardless.
        $limit = $requested > 0 ? min($requested, 500) : 500;

        // BrowseMetadata asks about the container itself rather than its
        // children. Answering it with the children makes some clients show the
        // folder inside itself.
        if ($flag === 'BrowseMetadata') {
            return $this->browseResponse('', 1, 1);
        }

        $result = $this->catalogue->browse($objectId, $start, $limit);

        $didl = $this->didl->write(
            $result['containers'],
            $result['items'],
            $objectId,
            $this->baseUrl($request),
        );

        $returned = count($result['containers']) + $result['items']->count();

        return $this->browseResponse($didl, $returned, $result['total']);
    }

    /**
     * The bytes.
     *
     * No play is recorded: a DLNA client is not a profile, and counting a TV's
     * playback against a household member's history would put listens in the
     * wrong place.
     */
    public function media(int $id): BinaryFileResponse
    {
        $item = $this->catalogue->find($id);

        abort_if($item === null, 404);

        $path = $item->playbackPath();

        abort_if($path === null, 404);

        // A file response, so Accept-Ranges is set and a client can seek
        // rather than refetch — the difference between scrubbing and not.
        return response()->file($path);
    }

    /** A SOAP Browse response, with the DIDL escaped into the Result element. */
    private function browseResponse(string $didl, int $returned, int $total): Response
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
                .'<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
                .' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
                .'<s:Body><u:BrowseResponse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
                .'<Result>%s</Result>'
                .'<NumberReturned>%d</NumberReturned>'
                .'<TotalMatches>%d</TotalMatches>'
                .'<UpdateID>1</UpdateID>'
                .'</u:BrowseResponse></s:Body></s:Envelope>',
            // Escaped: the spec carries the DIDL document as *text* inside
            // Result, and clients reject a Result holding real XML.
            htmlspecialchars($didl, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $returned,
            $total,
        );

        return response($xml, 200, ['Content-Type' => 'text/xml; charset="utf-8"']);
    }

    private function soapFault(string $message, int $code): Response
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
                .'<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
                .'<s:Body><s:Fault><faultcode>s:Client</faultcode>'
                .'<faultstring>UPnPError</faultstring><detail>'
                .'<UPnPError xmlns="urn:schemas-upnp-org:control-1-0">'
                .'<errorCode>%d</errorCode><errorDescription>%s</errorDescription>'
                .'</UPnPError></detail></s:Fault></s:Body></s:Envelope>',
            $code,
            htmlspecialchars($message, ENT_XML1, 'UTF-8'),
        );

        return response($xml, 500, ['Content-Type' => 'text/xml; charset="utf-8"']);
    }

    /**
     * Pulls one argument out of a SOAP body.
     *
     * By regex rather than an XML parser on purpose: clients namespace these
     * elements inconsistently (`<ObjectID>`, `<u:ObjectID>`, `<ns0:ObjectID>`)
     * and a strict parse fails on the ones that matter.
     */
    private function soapValue(string $body, string $name): ?string
    {
        if (preg_match('/<(?:[\w-]+:)?'.preg_quote($name, '/').'[^>]*>(.*?)<\//s', $body, $m) !== 1) {
            return null;
        }

        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** The address this server is reachable at, from the request that arrived. */
    private function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    private function argument(string $name, string $direction, string $related): string
    {
        return sprintf(
            '<argument><name>%s</name><direction>%s</direction>'
                .'<relatedStateVariable>%s</relatedStateVariable></argument>',
            $name,
            $direction,
            $related,
        );
    }

    private function stateVariable(string $name, string $type): string
    {
        return sprintf(
            '<stateVariable sendEvents="no"><name>%s</name><dataType>%s</dataType></stateVariable>',
            $name,
            $type,
        );
    }
}
