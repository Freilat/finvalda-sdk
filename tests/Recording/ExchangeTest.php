<?php

declare(strict_types=1);

namespace Finvalda\Tests\Recording;

use Finvalda\Enums\CredentialMode;
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

    public function test_masked_mode_masks_credential_headers(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['UserName' => 'demo', 'Password' => 'secret', 'ConnString' => 'Server=db'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame('***', $masked->headers['Password']);
        $this->assertSame('***', $masked->headers['ConnString']);
        $this->assertSame('demo', $masked->headers['UserName']);
        // Original is untouched
        $this->assertSame('secret', $exchange->headers['Password']);
    }

    public function test_env_mode_replaces_credentials_with_placeholders(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['UserName' => 'demo', 'Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $env = $exchange->withCredentials(CredentialMode::Env);

        $this->assertSame('$FVS_PASSWORD', $env->headers['Password']);
        $this->assertSame('demo', $env->headers['UserName']);
        $this->assertStringNotContainsString('secret', (string) $env);
    }

    public function test_real_mode_returns_the_exchange_unchanged(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $this->assertSame('secret', $exchange->withCredentials(CredentialMode::Real)->headers['Password']);
    }

    public function test_env_mode_curl_interpolates_the_placeholder(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['UserName' => 'demo', 'Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->withCredentials(CredentialMode::Env)->toCurl();

        // Literal chunk single-quoted, placeholder double-quoted so the shell expands it
        $this->assertStringContainsString('-H \'Password: \'"$FVS_PASSWORD"', $curl);
        $this->assertStringContainsString("-H 'UserName: demo'", $curl);
    }

    public function test_env_mode_curl_interpolates_a_placeholder_inside_a_json_body(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUser',
            headers: [],
            body: '{"sKodas":"ADMIN","sPassword":"secret"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->withCredentials(CredentialMode::Env)->toCurl();

        $this->assertStringNotContainsString('secret', $curl);
        $this->assertStringContainsString('\'{"sKodas":"ADMIN","sPassword":"\'"$FVS_SPASSWORD"\'"}\'', $curl);
    }

    public function test_masked_mode_masks_credentials_in_the_url_query(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUserPassword?sKodas=ADMIN&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Masked)->url;

        $this->assertStringNotContainsString('secret', $url);
        // The mask stays literal — percent-encoding it would make it unreadable
        $this->assertStringContainsString('sPassword=***', $url);
        $this->assertStringContainsString('sKodas=ADMIN', $url);
    }

    public function test_env_mode_substitutes_credentials_in_the_url_query(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUserPassword?sKodas=ADMIN&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Env)->url;

        $this->assertStringNotContainsString('secret', $url);
        // The placeholder stays literal, so toCurl() can quote it for the shell
        $this->assertStringContainsString('sPassword=$FVS_SPASSWORD', $url);
    }

    public function test_env_mode_url_placeholder_is_shell_expandable_in_curl(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=topsecret123',
            headers: ['UserName' => 'demo', 'Password' => 'conn-pass'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->withCredentials(CredentialMode::Env)->toCurl();

        $this->assertStringNotContainsString('topsecret123', $curl);
        // Literal chunk single-quoted, placeholder double-quoted so a shell expands it
        $this->assertStringContainsString(
            "'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword='\"\$FVS_SPASSWORD\"",
            $curl,
        );
        $this->assertStringNotContainsString('%24FVS_SPASSWORD', $curl);
    }

    public function test_query_substitution_keeps_rfc3986_encoding_of_other_parameters(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sPavadinimas=John%20Doe&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Masked)->url;

        // RFC 3986, as HttpClient::recordedUrl() and Guzzle both encode it — not RFC 1738's '+'
        $this->assertStringContainsString('sPavadinimas=John%20Doe', $url);
        $this->assertStringNotContainsString('John+Doe', $url);
        $this->assertStringContainsString('sPassword=***', $url);
    }

    public function test_query_substitution_survives_array_and_empty_parameters(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sKodai%5B0%5D=A&sKodai%5B1%5D=B&sTuscias=&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Masked)->url;

        $this->assertStringContainsString('sKodai%5B0%5D=A', $url);
        $this->assertStringContainsString('sKodai%5B1%5D=B', $url);
        $this->assertStringContainsString('sTuscias=', $url);
        $this->assertStringContainsString('sPassword=***', $url);
    }

    public function test_substitutes_a_userinfo_password_in_the_url(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://svcuser:svcpass@example.com/FvsServicePure.svc/GetPrekes?sKodas=A',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);
        $env = $exchange->withCredentials(CredentialMode::Env);

        $this->assertSame(
            'https://svcuser:***@example.com/FvsServicePure.svc/GetPrekes?sKodas=A',
            $masked->url,
        );
        $this->assertSame(
            'https://svcuser:$FVS_PASSWORD@example.com/FvsServicePure.svc/GetPrekes?sKodas=A',
            $env->url,
        );
        // A userinfo without a password component is left alone
        $this->assertStringNotContainsString('svcpass', $masked->url);
    }

    public function test_leaves_a_userinfo_without_a_password_untouched(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://svcuser@example.com/FvsServicePure.svc/GetPrekes',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $this->assertSame($exchange->url, $exchange->withCredentials(CredentialMode::Masked)->url);
    }

    public function test_scrubs_the_credential_out_of_a_transport_error_message(): void
    {
        // Guzzle embeds the request URI in ConnectException/RequestException messages
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=topsecret123',
            headers: ['UserName' => 'demo', 'Password' => 'conn-pass-9'],
            body: null,
            statusCode: null,
            reasonPhrase: null,
            responseHeaders: [],
            responseBody: null,
            durationMs: 30.0,
            error: 'cURL error 6: Could not resolve host: example.com for '
                . 'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=topsecret123',
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertStringNotContainsString('topsecret123', (string) $masked->error);
        $this->assertStringContainsString('sPassword=***', (string) $masked->error);
        $this->assertStringContainsString('Could not resolve host', (string) $masked->error);
        $this->assertStringNotContainsString('topsecret123', (string) $masked);
        $this->assertStringNotContainsString('topsecret123', $masked->toCurl());
        $this->assertStringNotContainsString('topsecret123', (string) json_encode($masked->toArray()));

        $env = $exchange->withCredentials(CredentialMode::Env);

        $this->assertStringContainsString('sPassword=$FVS_SPASSWORD', (string) $env->error);
        $this->assertStringNotContainsString('topsecret123', (string) json_encode($env->toArray()));
        $this->assertStringNotContainsString('conn-pass-9', (string) json_encode($env->toArray()));
    }

    public function test_scrubs_a_credential_echoed_back_in_the_response(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=topsecret123',
            headers: ['Password' => 'conn-pass-9'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: ['X-Echo' => ['pw=topsecret123']],
            responseBody: '{"AccessResult":"Success","sPassword":"topsecret123","sConn":"conn-pass-9"}',
            durationMs: 1.0,
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame(['X-Echo' => ['pw=***']], $masked->responseHeaders);
        $this->assertStringNotContainsString('topsecret123', (string) $masked->responseBody);
        $this->assertStringNotContainsString('conn-pass-9', (string) $masked->responseBody);
        $this->assertStringNotContainsString('topsecret123', (string) $masked);
        $this->assertStringNotContainsString('topsecret123', (string) json_encode($masked->toArray()));

        $env = $exchange->withCredentials(CredentialMode::Env);

        $this->assertStringContainsString('"sPassword":"$FVS_SPASSWORD"', (string) $env->responseBody);
        $this->assertStringContainsString('"sConn":"$FVS_PASSWORD"', (string) $env->responseBody);
    }

    public function test_scrub_skips_empty_credential_values(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sPassword=',
            headers: ['Password' => ''],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: 'plain body',
            durationMs: 1.0,
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);

        // An empty credential must not turn every byte boundary into a mask
        $this->assertSame('plain body', $masked->responseBody);
    }

    public function test_scrub_replaces_the_longest_credential_first(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser?sPassword=secret123',
            headers: ['Password' => 'secret'],
            body: null,
            statusCode: 500,
            reasonPhrase: 'Internal Server Error',
            responseHeaders: [],
            responseBody: 'saw secret123 and secret',
            durationMs: 1.0,
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);

        // 'secret' is a prefix of 'secret123'; the longer value must go first
        $this->assertSame('saw *** and ***', $masked->responseBody);
    }

    public function test_masked_mode_masks_credentials_in_a_json_body(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUser',
            headers: [],
            body: '{"sKodas":"ADMIN","sPassword":"secret"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $body = $exchange->withCredentials(CredentialMode::Masked)->body;

        $this->assertStringNotContainsString('secret', (string) $body);
        $this->assertStringContainsString('"sPassword":"***"', (string) $body);
    }

    public function test_masked_mode_substitutes_the_query_by_offset_not_by_content(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/sKodas=ADMIN&sPassword=secret/api?sKodas=ADMIN&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Masked)->url;

        // The path occurrence is untouched; only the real query is substituted.
        $this->assertStringContainsString('/sKodas=ADMIN&sPassword=secret/api?', $url);
        $this->assertStringContainsString('sPassword=***', $url);
        $this->assertStringNotContainsString('sPassword=secret', substr($url, (int) strpos($url, '?')));
    }

    public function test_a_credential_free_query_is_returned_byte_identical(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC&tData=2026-01-01',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Masked)->url;

        $this->assertSame($exchange->url, $url);
    }

    public function test_substitution_leaves_a_credential_free_body_byte_identical(): void
    {
        $exchange = $this->operationExchange();
        $masked = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame($exchange->body, $masked->body);
        $this->assertSame($exchange->url, $masked->url);
    }

    public function test_substitution_preserves_response_and_attempt_data(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewOperation',
            headers: ['Password' => 'secret'],
            body: null,
            statusCode: 500,
            reasonPhrase: 'Internal Server Error',
            responseHeaders: ['X-Trace' => ['abc']],
            responseBody: 'boom',
            durationMs: 7.5,
            error: 'Server error: 500',
            attempt: 3,
        );

        $substituted = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame(500, $substituted->statusCode);
        $this->assertSame('Internal Server Error', $substituted->reasonPhrase);
        $this->assertSame(['X-Trace' => ['abc']], $substituted->responseHeaders);
        $this->assertSame('boom', $substituted->responseBody);
        $this->assertSame(7.5, $substituted->durationMs);
        $this->assertSame('Server error: 500', $substituted->error);
        $this->assertSame(3, $substituted->attempt);
    }
}
