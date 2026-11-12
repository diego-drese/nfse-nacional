# Changelog

## 0.3.0 (2026-11-12)

- ISS com **não incidência** (`Dps::$issTaxation = Dps::ISS_NON_INCIDENCE`, `tribISSQN = 4`), para operações fora do ISS
  como a locação de bem móvel (STF Súmula Vinculante 31). Vai sem alíquota (`pAliq`).
- `DpsRules`: recusa antes de enviar uma tributação de ISS não suportada (só 1 e 4).
- Compatível com a 0.2: o padrão continua tributável (`tribISSQN = 1`).

## 0.2.0 (2026-11-13)

- Reforma tributária: grupo IBSCBS opcional na DPS (`Dps::$ibsCbs`): classificação (cIndOp, CST, cClassTrib), consumo
  final, compra governamental e repasses/reembolsos de terceiros com o documento do fornecedor (`Reimbursement`).
- `Result::$ibsCbs`: base, IBS, CBS, total e alíquotas efetivas calculados pelo sistema nacional, lidos da NFS-e.
- `DpsRules`: regras que o schema não pega, checadas antes de enviar (código `REGRA`): NBS obrigatório com IBS/CBS,
  documento e descrição do repasse, soma dos repasses, valor maior que zero.
- Compatível com a 0.1: tudo novo é opcional.

## 0.1.1 (2026-11-13)

- Corrige o cancelamento no PHP 8.2 (`mb_str_pad` só existe no 8.3).
- README: aviso de projeto feito por IA (uso por conta e risco), a referência (nfse-nacional/nfse-php) e o porquê do
  pacote; guia de desenvolvimento em `docs/DESENVOLVIMENTO.md`.

## 0.1.0 (2026-11-13)

Primeira versão.

- DPS no leiaute 1.01 (prestador do Simples Nacional ou não optante, tomador com CPF/CNPJ e endereço nacional, ISS
  tributável sem retenção, alíquota e percentual total do Simples).
- Validação contra os schemas oficiais antes de enviar.
- Assinatura XMLDSig com o certificado A1 (PFX), inclusive cifra antiga (RC2-40) pelo `openssl -legacy`.
- Sefin Nacional: emitir, consultar pela DPS (reenvio sem duplicar), cancelar (evento 101101). ADN: DANFSe.
- `Result` com o XML enviado, o status HTTP e a resposta crua; registro opcional de cada chamada; transporte trocável.
