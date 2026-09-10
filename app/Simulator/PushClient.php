<?php

namespace App\Simulator;

use App\Http\Middleware\VerifyInstitutionSignature as Sig;
use App\Models\InstitutionCredential;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Signer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Signs a payload and delivers it to the ingest API, exactly as an institution's
 * own client would.
 *
 * NOTHING IS BYPASSED. Both transports produce a real signed HTTP request that
 * goes through routing, the signature middleware, rate limiting and the
 * controller. A simulator that inserted rows directly would prove only that the
 * database accepts writes, which nobody doubted.
 *
 *   internal — dispatches the Request through this application's own HTTP
 *              kernel. Full middleware stack, no network, no server to start.
 *   http     — an actual socket to a running server. Slower, needs
 *              `php artisan serve`, and is the honest end-to-end check.
 *
 * Internal is the default because a demo that requires two terminals is a demo
 * nobody runs.
 */
class PushClient
{
    public function __construct(
        private InstitutionCredential $credential,
        private string $secret,
        private ?string $baseUrl = null
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function pushReport(array $payload): array
    {
        return $this->send('/api/v1/reports', Canonical::encode($payload));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function heartbeat(array $body = []): array
    {
        return $this->send('/api/v1/heartbeat', json_encode($body ?: ['note' => 'Alive, nothing new to report.']));
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function send(string $path, string $body): array
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $signature = Signer::signRaw($body, $timestamp, $this->secret);

        return $this->baseUrl
            ? $this->overHttp($path, $body, $timestamp, $signature)
            : $this->throughKernel($path, $body, $timestamp, $signature);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function overHttp(string $path, string $body, string $timestamp, string $signature): array
    {
        $response = Http::withHeaders($this->headers($timestamp, $signature))
            ->withBody($body, 'application/json')
            ->timeout(30)
            ->post(rtrim($this->baseUrl, '/').$path);

        return ['status' => $response->status(), 'body' => $response->json() ?? []];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function throughKernel(string $path, string $body, string $timestamp, string $signature): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($this->headers($timestamp, $signature) as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        $request = Request::create($path, 'POST', [], [], [], $server, $body);

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true) ?? [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $timestamp, string $signature): array
    {
        return [
            Sig::KEY_HEADER => $this->credential->key_id,
            Sig::TIMESTAMP_HEADER => $timestamp,
            Sig::SIGNATURE_HEADER => $signature,
        ];
    }
}
