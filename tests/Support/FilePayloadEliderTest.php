<?php

declare(strict_types=1);

namespace Finvalda\Tests\Support;

use Finvalda\Support\FilePayloadElider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilePayloadEliderTest extends TestCase
{
    public function test_it_elides_the_base64_document_a_report_endpoint_answers_with(): void
    {
        // The real MakeInvoice wire shape: the whole PDF base64'd into one key.
        $body = '{"AccessResult":"Success","error":"","data":"' . str_repeat('A', 58_000) . '"}';

        $elided = FilePayloadElider::apply($body);

        $this->assertStringContainsString('"data":"[elided 58000 bytes]"', $elided);
        $this->assertLessThan(200, strlen($elided));
        $this->assertIsArray(json_decode($elided, true), 'elided output must stay valid JSON');
    }

    public function test_it_elides_the_hex_document_an_upload_request_carries(): void
    {
        // Documents::uploadFile() bin2hex()es the file, so the REQUEST body is
        // twice the file size — larger than the response payloads, and missed
        // entirely if only response keys are covered.
        $body = '{"sFileName":"invoice.pdf","sFileContent":"' . str_repeat('ab', 40_000) . '"}';

        $elided = FilePayloadElider::apply($body);

        $this->assertStringContainsString('"sFileContent":"[elided 80000 bytes]"', $elided);
        $this->assertStringContainsString('"sFileName":"invoice.pdf"', $elided);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function payloadKeys(): array
    {
        return [
            'data' => ['data'],
            'fileContents' => ['fileContents'],
            'FileContents' => ['FileContents'],
            'file_contents' => ['file_contents'],
            'sFileContent' => ['sFileContent'],
        ];
    }

    #[DataProvider('payloadKeys')]
    public function test_it_covers_every_spelling_a_payload_arrives_under(string $key): void
    {
        $body = '{"' . $key . '":"' . str_repeat('A', 1_000) . '"}';

        $this->assertStringContainsString("\"{$key}\":\"[elided 1000 bytes]\"", FilePayloadElider::apply($body));
    }

    public function test_it_leaves_structured_values_alone(): void
    {
        // The safety property that makes keying on a name as generic as `data`
        // defensible: only string values can match.
        $body = '{"AccessResult":"Success","data":[{"sKodas":"P1"},{"sKodas":"P2"}]}';

        $this->assertSame($body, FilePayloadElider::apply($body));
    }

    public function test_it_leaves_short_values_alone(): void
    {
        $body = '{"fileContents":"JVBERi0xLjcK"}';

        $this->assertSame($body, FilePayloadElider::apply($body));
    }

    public function test_it_leaves_a_body_without_a_payload_alone(): void
    {
        $body = '{"AccessResult":"Fail","error":"Report with code \'U_PARD_H01\' does not exist!"}';

        $this->assertSame($body, FilePayloadElider::apply($body));
    }

    public function test_it_elides_every_payload_in_one_body(): void
    {
        $body = '{"data":"' . str_repeat('A', 600) . '","fileContents":"' . str_repeat('B', 700) . '"}';

        $elided = FilePayloadElider::apply($body);

        $this->assertStringContainsString('"data":"[elided 600 bytes]"', $elided);
        $this->assertStringContainsString('"fileContents":"[elided 700 bytes]"', $elided);
    }

    public function test_it_passes_through_null_and_empty_bodies(): void
    {
        $this->assertNull(FilePayloadElider::apply(null));
        $this->assertSame('', FilePayloadElider::apply(''));
    }

    public function test_the_threshold_is_adjustable(): void
    {
        $body = '{"data":"' . str_repeat('A', 100) . '"}';

        $this->assertSame($body, FilePayloadElider::apply($body));
        $this->assertStringContainsString('[elided 100 bytes]', FilePayloadElider::apply($body, 50));
    }
}
