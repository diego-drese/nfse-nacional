# Changelog

## 0.1.0 (2026-11-13)

Primeira versão.

- DPS no leiaute 1.01 (prestador do Simples Nacional ou não optante, tomador com CPF/CNPJ e endereço nacional, ISS
  tributável sem retenção, alíquota e percentual total do Simples).
- Validação contra os schemas oficiais antes de enviar.
- Assinatura XMLDSig com o certificado A1 (PFX), inclusive cifra antiga (RC2-40) pelo `openssl -legacy`.
- Sefin Nacional: emitir, consultar pela DPS (reenvio sem duplicar), cancelar (evento 101101). ADN: DANFSe.
- `Result` com o XML enviado, o status HTTP e a resposta crua; registro opcional de cada chamada; transporte trocável.
