<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional;

use DateTimeImmutable;
use DiegoDrese\NfseNacional\Exceptions\CertificateException;

/**
 * Certificado A1 (PFX/P12) em memória: chave privada e certificado em PEM. Muitos A1 brasileiros vêm com cifra antiga (RC2-40)
 * que o OpenSSL 3 só lê com o provedor "legacy": se a leitura normal falhar, converte pela linha de comando `openssl pkcs12
 * -legacy` num diretório temporário apagado em seguida.
 */
final class Certificate
{
    /** @param array<string, mixed> $info */
    private function __construct(public readonly string $certPem, public readonly string $keyPem, private readonly array $info) {}

    public static function fromPfx(string $pfx, string $password): self
    {
        $certs = [];
        if (! @openssl_pkcs12_read($pfx, $certs, $password)) {
            $certs = self::legacy($pfx, $password);
        }
        $info = openssl_x509_parse((string) ($certs['cert'] ?? ''));
        if (! is_array($info) || empty($certs['pkey'])) {
            throw new CertificateException('Não foi possível ler o certificado: confira o arquivo .pfx e a senha.');
        }

        return new self((string) $certs['cert'], (string) $certs['pkey'], $info);
    }

    /** CNPJ do titular (e-CNPJ: no fim do CN, "RAZAO SOCIAL:00000000000000"). */
    public function cnpj(): ?string
    {
        return preg_match('/:(\d{14})$/', (string) ($this->info['subject']['CN'] ?? ''), $m) ? $m[1] : null;
    }

    public function subject(): string
    {
        return (string) ($this->info['subject']['CN'] ?? 'Certificado');
    }

    public function validUntil(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp((int) ($this->info['validTo_time_t'] ?? 0));
    }

    public function expired(): bool
    {
        return $this->validUntil() < new DateTimeImmutable;
    }

    /** Certificado sem cabeçalho, para o X509Certificate da assinatura. */
    public function base64(): string
    {
        return (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $this->certPem);
    }

    public function sign(string $content): string
    {
        $signature = '';
        if (! openssl_sign($content, $signature, $this->keyPem, OPENSSL_ALGO_SHA1)) {
            throw new CertificateException('Falha ao assinar com o certificado.');
        }

        return $signature;
    }

    /** @return array{cert: string, pkey: string} */
    private static function legacy(string $pfx, string $password): array
    {
        $dir = sys_get_temp_dir().'/nfse-pfx-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        try {
            file_put_contents("{$dir}/in.pfx", $pfx);
            file_put_contents("{$dir}/pass", $password);
            chmod("{$dir}/pass", 0600);
            $proc = proc_open(['openssl', 'pkcs12', '-legacy', '-in', "{$dir}/in.pfx", '-passin', "file:{$dir}/pass", '-nodes'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
            $ok = is_resource($proc) && proc_close($proc) === 0;
            if (! $ok || ! preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $out, $cert)
                || ! preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----.+?-----END (?:RSA )?PRIVATE KEY-----/s', $out, $key)) {
                throw new CertificateException('Não foi possível ler o certificado: confira o arquivo .pfx e a senha.');
            }

            return ['cert' => $cert[0], 'pkey' => $key[0]];
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }
}
