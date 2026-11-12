<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Xml;

use DiegoDrese\NfseNacional\Certificate;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Assinatura XMLDSig envelopada do elemento com Id (infDPS, infPedReg), como a NFS-e Nacional pede: C14N, transformações
 * enveloped-signature + C14N, RSA-SHA1, e o certificado no KeyInfo. A Signature fica irmã do elemento assinado. Adaptado do
 * nfse-nacional/nfse-php (MIT).
 */
final class XmlSigner
{
    private const DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public function __construct(private readonly Certificate $certificate) {}

    public function sign(string $xml, string $tag): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);
        $node = $dom->getElementsByTagName($tag)->item(0);
        if (! $node instanceof DOMElement || $node->getAttribute('Id') === '') {
            throw new RuntimeException("Elemento {$tag} com Id não encontrado para assinar.");
        }
        $digest = base64_encode(hash('sha1', $node->C14N(false, false), true));

        $signature = $dom->createElementNS(self::DSIG, 'Signature');
        $node->parentNode?->appendChild($signature);
        $signedInfo = $signature->appendChild($dom->createElement('SignedInfo'));
        $signedInfo->appendChild($dom->createElement('CanonicalizationMethod'))->setAttribute('Algorithm', self::C14N);
        $signedInfo->appendChild($dom->createElement('SignatureMethod'))->setAttribute('Algorithm', self::DSIG.'rsa-sha1');
        $reference = $signedInfo->appendChild($dom->createElement('Reference'));
        $reference->setAttribute('URI', '#'.$node->getAttribute('Id'));
        $transforms = $reference->appendChild($dom->createElement('Transforms'));
        $transforms->appendChild($dom->createElement('Transform'))->setAttribute('Algorithm', self::DSIG.'enveloped-signature');
        $transforms->appendChild($dom->createElement('Transform'))->setAttribute('Algorithm', self::C14N);
        $reference->appendChild($dom->createElement('DigestMethod'))->setAttribute('Algorithm', self::DSIG.'sha1');
        $reference->appendChild($dom->createElement('DigestValue', $digest));

        $value = base64_encode($this->certificate->sign($signedInfo->C14N(false, false)));
        $signature->appendChild($dom->createElement('SignatureValue', $value));
        $keyInfo = $signature->appendChild($dom->createElement('KeyInfo'));
        $keyInfo->appendChild($dom->createElement('X509Data'))->appendChild($dom->createElement('X509Certificate', $this->certificate->base64()));

        return str_replace(["\n", "\r", "\t"], '', (string) $dom->saveXML());
    }
}
