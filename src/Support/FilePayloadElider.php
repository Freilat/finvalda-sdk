<?php

declare(strict_types=1);

namespace Finvalda\Support;

/**
 * Replaces a whole-file payload in a raw request or response body with a size
 * marker.
 *
 * MakeInvoice/MakeReport/GetAutoReport answer with a document base64'd into one
 * JSON string — around 58 KB for a typical invoice PDF. `InsertDocument` sends
 * one the other way as hex, which is twice the file's size. Both sit under
 * BodyTruncator's budget, so both are logged in full, and neither is readable.
 *
 * Lowering the byte budget instead would be the wrong trade: the budget exists
 * to preserve large Get*Set / GetOperations responses, which ARE worth reading.
 * The bytes worth dropping are separable by name, so drop them by name.
 */
final class FilePayloadElider
{
    /**
     * Keys that can hold a whole file in a Pure REST body.
     *
     * `data` is what MakeInvoice answers with:
     * {"AccessResult":"Success","error":"","data":"JVBERi0xLjcg…"}. The
     * fileContents spellings are the ones DecodesBinaryResponse::extractFileContents()
     * accepts; keep the two lists in step. `sFileContent` is the request side —
     * Documents::upload() and uploadFile().
     */
    public const PAYLOAD_KEYS = ['data', 'fileContents', 'FileContents', 'file_contents', 'sFileContent'];

    /** Shorter values are left alone — error text, one-line statuses. */
    public const MIN_BYTES = 512;

    public static function apply(?string $body, int $minBytes = self::MIN_BYTES): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $keys = implode('|', self::PAYLOAD_KEYS);

        // Deliberately a regex over the raw string: decoding a 100 KB body to
        // rewrite one key and re-encoding it costs more than the write it saves.
        //
        // [^"] cannot cross a JSON string boundary, so only STRING values match —
        // "data":[…] and "data":{…} are untouched. That is what makes keying on a
        // name as generic as `data` safe.
        return preg_replace_callback(
            '/"(' . $keys . ')"\s*:\s*"([^"]{' . $minBytes . ',})"/',
            static fn (array $m): string => '"' . $m[1] . '":"[elided ' . strlen($m[2]) . ' bytes]"',
            $body
        ) ?? $body;
    }
}
