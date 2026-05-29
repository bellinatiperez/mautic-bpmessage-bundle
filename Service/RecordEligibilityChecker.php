<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

/**
 * Componente puro (sem DB/HTTP) para decidir se um registro do lote BpMessage
 * pode ser enviado:
 *  - campos obrigatórios (CPF/CNPJ e Contrato) preenchidos;
 *  - deduplicação de ENTREGA duplicada no lote: a duplicidade é por
 *    **mesmo contrato + mesmo entregável** (telefone OU e-mail idêntico).
 *    Telefones DIFERENTES do mesmo contrato são entregas distintas e NÃO
 *    devem ser colapsados (cada um é enviado).
 *
 * O telefone também é obrigatório, mas é validado no fluxo de resolução de
 * telefone do LotManager (lead/crm_api), que já marca "Contato sem telefone...".
 */
final class RecordEligibilityChecker
{
    public const REASON_CPF       = 'CPF/CNPJ não informado';
    public const REASON_CONTRACT  = 'Contrato não informado';
    public const REASON_DUPLICATE = 'Entrega duplicada no lote (mesmo contrato e telefone/e-mail)';

    /**
     * Retorna o motivo do não-envio (string) quando um campo obrigatório está
     * vazio, ou null quando CPF/CNPJ e Contrato estão preenchidos.
     */
    public function validateRequiredFields(?string $cpfCnpj, ?string $contract): ?string
    {
        if ('' === $this->onlyDigits($cpfCnpj)) {
            return self::REASON_CPF;
        }

        if ('' === trim((string) $contract)) {
            return self::REASON_CONTRACT;
        }

        return null;
    }

    /**
     * Entregável canônico de um payload do lote:
     *  - e-mail (prefixo "e:" + minúsculas) quando há destinatário "to";
     *  - senão, telefone (prefixo "p:" + apenas dígitos de areaCode+phone).
     * Retorna '' quando ainda não há entregável resolvido (ex.: telefone não
     * resolvido) — nesse caso NÃO se deduplica (não derruba entregas legítimas).
     *
     * @param array<string,mixed> $payload
     */
    public function deliverableFromPayload(array $payload): string
    {
        $email = strtolower(trim((string) ($payload['to'] ?? '')));
        if ('' !== $email) {
            return 'e:'.$email;
        }

        $phone = $this->onlyDigits((string) ($payload['areaCode'] ?? '').(string) ($payload['phone'] ?? ''));
        if ('' !== $phone) {
            return 'p:'.$phone;
        }

        return '';
    }

    /**
     * Chave de deduplicação de ENTREGA: mesmo contrato + mesmo entregável.
     * Retorna null quando falta contrato OU entregável — nesses casos NÃO se
     * deduplica, evitando colapsar telefones diferentes do mesmo contrato
     * (ou registros ainda sem telefone resolvido).
     */
    public function dedupKey(?string $contract, string $deliverable): ?string
    {
        $contract = trim((string) $contract);
        if ('' === $contract || '' === $deliverable) {
            return null;
        }

        return $contract.'|'.$deliverable;
    }

    private function onlyDigits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }
}
