<?php

use App\Modules\Connectors\Core\ConnectorConfig;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/* Shared fixtures of the AI tests: an active Anthropic connector, a faked answer, a minimal PDF. */

function assistantConnector(): ConnectorConfig
{
    tenant();
    $config = new ConnectorConfig(['connector_key' => 'ai', 'company_id' => null]);
    $config->fill(['enabled' => true, 'settings' => ['provider' => 'anthropic'], 'secrets' => ['api_key' => 'sk-test-'.'assistant0123456789']])->save();

    return $config;
}

/** @param  array<string, mixed>|string  $answer */
function fakeAssistantAi(array|string $answer): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['https://api.anthropic.com/*' => is_string($answer)
        ? Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $answer]]])
        : Http::response($answer)]);
}

function sentToAssistant(): string
{
    $sent = Http::recorded()->first();
    expect($sent)->not->toBeNull();
    /** @var Request $request */
    $request = $sent[0];

    return (string) collect($request->data()['messages'])->pluck('content')->flatten()->implode("\n");
}

/**
 * A minimal valid PDF whose pages show the given lines (ASCII), so
 * pdftotext reads them back.
 *
 * @param  list<list<string>>  $pages
 */
function assistantPdf(array $pages): string
{
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $next = 4;
    $bodies = [];
    foreach ($pages as $lines) {
        $stream = "BT /F1 9 Tf 10 TL 20 780 Td\n";
        foreach ($lines as $line) {
            $stream .= '('.addcslashes($line, '()\\').") Tj T*\n";
        }
        $stream .= 'ET';
        $pageId = $next++;
        $contentId = $next++;
        $kids[] = "{$pageId} 0 R";
        $bodies[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        $bodies[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects += $bodies;
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}
