<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

/**
 * Componente puro (sem DB/HTTP) para decidir se um registro do lote BpMessage
 * pode ser enviado:
 *  - campos obrigatórios (CPF/CNPJ e Contrato) preenchidos;
 *  - deduplicação de CONTATO duplicado no lote (mesmo contrato/CPF em leads
 *    diferentes deve gerar UM envio).
 *
 * O telefone também é obrigatório, mas é validado no fluxo de resolução de
 * telefone do LotManager (lead/crm_api), que já marca "Contato sem telefone...".
 */
final class RecordEligibilityChecker
{
    public const REASON_CPF       = 'CPF/CNPJ não informado';
    public const REASON_CONTRACT  = 'Contrato não informado';
    public const REASON_DUPLICATE = 'Contato/contrato duplicado no lote';

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
     * Chave canônica de identidade do contato no lote, usada para colapsar
     * leads duplicados do mesmo contrato. Baseada em CPF/CNPJ + Contrato
     * (o que também está disponível nos payloads já enviados, p/ seed).
     * Dois contratos diferentes geram chaves diferentes (não são deduplicados
     * entre si).
     */
    public function contactKey(?string $cpfCnpj, ?string $contract): string
    {
        return $this->onlyDigits($cpfCnpj).'|'.trim((string) $contract);
    }

    /**
     * Indica se há identidade suficiente para deduplicar (evita colapsar tudo
     * quando CPF/CNPJ e Contrato estão ambos ausentes).
     */
    public function hasContactIdentity(?string $cpfCnpj, ?string $contract): bool
    {
        return '' !== $this->onlyDigits($cpfCnpj) || '' !== trim((string) $contract);
    }

    private function onlyDigits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }
}
