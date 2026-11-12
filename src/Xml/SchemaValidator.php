<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Xml;

use DOMDocument;

/**
 * Valida o XML contra os schemas oficiais (resources/schemas, leiaute 1.01) antes de enviar: erro de leiaute aparece aqui,
 * com o campo e a linha, sem gastar uma chamada na Sefin. Leiaute novo da Receita = trocar os .xsd e ajustar o DpsXml.
 */
final class SchemaValidator
{
    public const DPS = 'DPS_v1.01.xsd';

    public const EVENT = 'pedRegEvento_v1.01.xsd';

    /** @return list<string> erros (vazio = válido) */
    public static function errors(string $xml, string $schema): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument;
            if (! $dom->loadXML($xml)) {
                return self::collect();
            }

            return $dom->schemaValidate(dirname(__DIR__, 2).'/resources/schemas/'.$schema) ? [] : self::collect();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> */
    private static function collect(): array
    {
        return array_values(array_map(
            fn ($e) => trim((string) preg_replace('/\{http:\/\/www\.sped\.fazenda\.gov\.br\/nfse\}/', '', $e->message)),
            libxml_get_errors(),
        ));
    }
}
