<?php

declare(strict_types=1);

namespace Finvalda\Tests\Recording;

use Finvalda\Recording\Exchange;
use PHPUnit\Framework\TestCase;

class ExchangeTest extends TestCase
{
    private function operationExchange(): Exchange
    {
        return new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewOperation',
            headers: ['UserName' => 'demo', 'Password' => '***', 'Accept' => 'application/json'],
            body: '{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{\"sZurnalas\":\"PARD\"}}"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: ['Content-Type' => ['application/json']],
            responseBody: '{"AccessResult":"Success","nResult":0}',
            durationMs: 128.4,
        );
    }

    public function test_formats_request_line_and_headers(): void
    {
        $output = $this->operationExchange()->toString();

        $this->assertStringContainsString(
            'POST https://example.com/FvsServicePure.svc/InsertNewOperation',
            $output,
        );
        $this->assertStringContainsString('UserName: demo', $output);
        $this->assertStringContainsString('Password: ***', $output);
    }

    public function test_expands_embedded_xmlstring_payload(): void
    {
        $output = $this->operationExchange()->toString();

        // The nested payload is decoded and indented, not left as an escaped string
        $this->assertStringContainsString('"xmlstring": {', $output);
        $this->assertStringContainsString('"sZurnalas": "PARD"', $output);
        $this->assertStringNotContainsString('\"sZurnalas\"', $output);
    }

    public function test_formats_status_line_with_reason_phrase_and_duration(): void
    {
        $this->assertStringContainsString('--- 200 OK (128.4 ms) ---', $this->operationExchange()->toString());
    }

    public function test_pretty_prints_json_response_body(): void
    {
        $this->assertStringContainsString('"AccessResult": "Success"', $this->operationExchange()->toString());
    }

    public function test_leaves_non_json_bodies_verbatim(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: '<FvsUser><sKodas>ADMIN</sKodas></FvsUser>',
            durationMs: 4.0,
        );

        $this->assertStringContainsString('<FvsUser><sKodas>ADMIN</sKodas></FvsUser>', $exchange->toString());
    }

    public function test_leaves_xmlstring_verbatim_when_it_is_not_json(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewItem',
            headers: [],
            body: '{"ItemClassName":"Fvs.Preke","xmlstring":"<Preke><sKodas>A</sKodas></Preke>"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 2.0,
        );

        $this->assertStringContainsString('"xmlstring": "<Preke><sKodas>A</sKodas></Preke>"', $exchange->toString());
    }

    public function test_formats_transport_error_when_there_is_no_response(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: [],
            body: null,
            statusCode: null,
            reasonPhrase: null,
            responseHeaders: [],
            responseBody: null,
            durationMs: 30_000.0,
            error: 'cURL error 28: Operation timed out',
        );

        $output = $exchange->toString();

        $this->assertStringContainsString('--- ERROR: cURL error 28: Operation timed out (30000 ms) ---', $output);
    }

    public function test_string_cast_returns_formatted_output(): void
    {
        $exchange = $this->operationExchange();

        $this->assertSame($exchange->toString(), (string) $exchange);
    }

    public function test_to_array_groups_request_and_response(): void
    {
        $array = $this->operationExchange()->toArray();

        $this->assertSame('POST', $array['request']['method']);
        $this->assertSame('https://example.com/FvsServicePure.svc/InsertNewOperation', $array['request']['url']);
        $this->assertSame('***', $array['request']['headers']['Password']);
        $this->assertSame(200, $array['response']['status_code']);
        $this->assertSame(128.4, $array['response']['duration_ms']);
        $this->assertNull($array['response']['error']);
        $this->assertSame(1, $array['attempt']);
    }

    public function test_curl_renders_method_url_headers_and_body(): void
    {
        $curl = $this->operationExchange()->toCurl();

        $this->assertStringContainsString(
            "curl -X POST 'https://example.com/FvsServicePure.svc/InsertNewOperation'",
            $curl,
        );
        $this->assertStringContainsString("-H 'UserName: demo'", $curl);
        $this->assertStringContainsString("-H 'Password: ***'", $curl);
        $this->assertStringContainsString("-H 'Content-Type: application/json'", $curl);
        // Body is byte-exact, not pretty-printed
        $this->assertStringContainsString(
            '-d \'{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{\"sZurnalas\":\"PARD\"}}"}\'',
            $curl,
        );
    }

    public function test_curl_uses_line_continuations(): void
    {
        $this->assertStringContainsString(" \\\n", $this->operationExchange()->toCurl());
    }

    public function test_curl_omits_data_flag_for_bodyless_requests(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC',
            headers: ['UserName' => 'demo'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: '{"AccessResult":"Success"}',
            durationMs: 12.0,
        );

        $curl = $exchange->toCurl();

        $this->assertStringNotContainsString('-d ', $curl);
        $this->assertStringNotContainsString('Content-Type', $curl);
        $this->assertStringContainsString("'https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC'", $curl);
    }

    public function test_curl_escapes_single_quotes_in_body(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewItem',
            headers: [],
            body: '{"sPavadinimas":"O\'Brien"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $this->assertStringContainsString('O\'\\\'\'Brien', $exchange->toCurl());
    }

    public function test_curl_keeps_a_captured_content_type_header(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewItem',
            headers: ['Content-Type' => 'text/xml'],
            body: '<Preke />',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->toCurl();

        $this->assertStringContainsString("-H 'Content-Type: text/xml'", $curl);
        $this->assertStringNotContainsString('application/json', $curl);
    }
}
