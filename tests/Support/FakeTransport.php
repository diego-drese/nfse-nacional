<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Tests\Support;

use DiegoDrese\NfseNacional\Certificate;
use DiegoDrese\NfseNacional\Http\Transport;

/** Sefin simulada: devolve as respostas na ordem e guarda as chamadas. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, body: ?string}> */
    public array $calls = [];

    /** @param list<array{0: int, 1: string}> $responses */
    public function __construct(private array $responses) {}

    public function send(string $method, string $url, ?string $body, array $headers, Certificate $certificate, int $timeout): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];

        return array_shift($this->responses) ?? [0, 'sem resposta configurada'];
    }
}
