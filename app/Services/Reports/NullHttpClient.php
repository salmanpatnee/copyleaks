<?php

namespace App\Services\Reports;

use Mpdf\Http\ClientInterface;
use Mpdf\PsrHttpMessageShim\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** mPDF never reaches the internet: every request gets a refusal. */
final class NullHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(403);
    }
}
