<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Tests;

use DiegoDrese\NfseNacional\Certificate;
use DiegoDrese\NfseNacional\Environment;
use DiegoDrese\NfseNacional\SefinClient;
use DiegoDrese\NfseNacional\Tests\Support\FakeTransport;
use DiegoDrese\NfseNacional\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SefinClientTest extends TestCase
{
    private Certificate $cert;

    protected function setUp(): void
    {
        [$pfx, $password] = TestCertificate::pfx();
        $this->cert = Certificate::fromPfx($pfx, $password);
    }

    private function client(FakeTransport $transport, ?callable $log = null, ?Certificate $cert = null): SefinClient
    {
        return new SefinClient(Environment::Homologacao, $cert ?? $this->cert, log: $log, transport: $transport);
    }

    private static function nfse(string $key, string $number): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS'.$key.'"><nNFSe>'.$number.'</nNFSe></infNFSe></NFSe>';

        return (string) json_encode(['chaveAcesso' => $key, 'nfseXmlGZipB64' => base64_encode((string) gzencode($xml))]);
    }

    public function test_issue_sends_the_signed_dps_and_returns_the_key_number_and_xml(): void
    {
        $key = str_repeat('7', 50);
        $logged = [];
        $transport = new FakeTransport([[201, self::nfse($key, '1234')]]);
        $result = $this->client($transport, function (...$args) use (&$logged): void { $logged[] = $args; })->issue(DpsTest::dps());

        $this->assertTrue($result->ok());
        $this->assertSame([$key, '1234'], [$result->accessKey, $result->number]);
        $this->assertStringContainsString('<nNFSe>1234</nNFSe>', (string) $result->nfseXml);
        $this->assertSame('POST', $transport->calls[0]['method']);
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $transport->calls[0]['url']);
        $sent = gzdecode(base64_decode(json_decode((string) $transport->calls[0]['body'], true)['dpsXmlGZipB64']));
        $this->assertSame($result->sentXml, $sent);
        $this->assertStringContainsString('<Signature', (string) $sent);
        $this->assertSame(201, $logged[0][2]);
    }

    public function test_a_sefin_rejection_comes_back_with_the_codes(): void
    {
        $body = (string) json_encode(['erros' => [['Codigo' => 'E0014', 'Descricao' => 'Conjunto de Série e Número já existe', 'Complemento' => 'nDPS 42']]]);
        $result = $this->client(new FakeTransport([[400, $body]]))->issue(DpsTest::dps());

        $this->assertSame('rejected', $result->status);
        $this->assertSame('E0014 - Conjunto de Série e Número já existe (nDPS 42)', $result->message());
        $this->assertSame([400, $body], [$result->httpStatus, $result->response]);
    }

    public function test_no_answer_or_server_error_is_unavailable_and_safe_to_retry(): void
    {
        $this->assertSame('unavailable', $this->client(new FakeTransport([[0, 'Connection timed out']]))->issue(DpsTest::dps())->status);
        $this->assertSame('unavailable', $this->client(new FakeTransport([[503, '']]))->issue(DpsTest::dps())->status);
    }

    public function test_an_invalid_dps_or_expired_certificate_never_reaches_the_sefin(): void
    {
        $transport = new FakeTransport([]);
        $this->assertSame('XSD', $this->client($transport)->issue(DpsTest::dps('1'))->errors[0]['code']);

        [$pfx, $password] = TestCertificate::pfx(days: 0);
        sleep(1); // validade zero: vence no segundo seguinte
        $this->assertStringContainsString('venceu', $this->client($transport, cert: Certificate::fromPfx($pfx, $password))->issue(DpsTest::dps())->message());
        $this->assertSame([], $transport->calls);
    }

    public function test_find_by_dps_cancel_and_danfse(): void
    {
        $key = str_repeat('8', 50);
        $transport = new FakeTransport([
            [200, (string) json_encode(['chaveAcesso' => $key])], [200, self::nfse($key, '99')],
            [404, ''],
            [201, (string) json_encode(['eventoXmlGZipB64' => 'x'])],
            [200, "%PDF-1.4\n..."],
        ]);
        $client = $this->client($transport);

        $this->assertSame('99', $client->findByDps(DpsTest::dps()->id())?->number);
        $this->assertNull($client->findByDps('DPS000'));
        $this->assertTrue($client->cancel($key, '11222333000181', 1, 'Erro na emissão da nota')->ok());
        $this->assertStringStartsWith('%PDF', (string) $client->danfse($key));
        $this->assertSame("https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse/{$key}/eventos", $transport->calls[3]['url']);
        $this->assertSame("https://adn.producaorestrita.nfse.gov.br/danfse/{$key}", $transport->calls[4]['url']);
    }
}
