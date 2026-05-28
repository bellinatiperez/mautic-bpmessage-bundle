<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Tests\Service;

use MauticPlugin\MauticBpMessageBundle\Service\RecordEligibilityChecker;
use PHPUnit\Framework\TestCase;

final class RecordEligibilityCheckerTest extends TestCase
{
    private RecordEligibilityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new RecordEligibilityChecker();
    }

    // ---------- Campos obrigatórios (Problema 1) ----------

    public function testCpfCnpjVazioRetornaMotivo(): void
    {
        self::assertSame(RecordEligibilityChecker::REASON_CPF, $this->checker->validateRequiredFields('', '12345'));
        self::assertSame(RecordEligibilityChecker::REASON_CPF, $this->checker->validateRequiredFields(null, '12345'));
        // String só com símbolos vira vazia após onlyDigits.
        self::assertSame(RecordEligibilityChecker::REASON_CPF, $this->checker->validateRequiredFields('--/--', '12345'));
    }

    public function testContratoVazioRetornaMotivo(): void
    {
        self::assertSame(RecordEligibilityChecker::REASON_CONTRACT, $this->checker->validateRequiredFields('59671548000106', ''));
        self::assertSame(RecordEligibilityChecker::REASON_CONTRACT, $this->checker->validateRequiredFields('59671548000106', '   '));
        self::assertSame(RecordEligibilityChecker::REASON_CONTRACT, $this->checker->validateRequiredFields('59671548000106', null));
    }

    public function testTodosObrigatoriosPreenchidosRetornaNull(): void
    {
        self::assertNull($this->checker->validateRequiredFields('59671548000106', '59671548000'));
    }

    public function testCpfVazioTemPrioridadeSobreContrato(): void
    {
        // Ambos vazios: reporta CPF/CNPJ primeiro (ordem determinística).
        self::assertSame(RecordEligibilityChecker::REASON_CPF, $this->checker->validateRequiredFields('', ''));
    }

    // ---------- Identidade do contato ----------

    public function testHasContactIdentity(): void
    {
        self::assertFalse($this->checker->hasContactIdentity('', ''));
        self::assertFalse($this->checker->hasContactIdentity(null, '   '));
        self::assertTrue($this->checker->hasContactIdentity('12345678900', null));
        self::assertTrue($this->checker->hasContactIdentity(null, '59671548000'));
    }

    public function testContactKeyMesmoContratoMesmaChave(): void
    {
        $a = $this->checker->contactKey('59671548000106', '59671548000');
        $b = $this->checker->contactKey('59671548000106', '59671548000');
        self::assertSame($a, $b);
    }

    public function testContactKeyIgnoraFormatacaoDoCpf(): void
    {
        // Mesmo CPF/CNPJ com pontuação diferente → mesma chave.
        $a = $this->checker->contactKey('59.671.548/0001-06', '59671548000');
        $b = $this->checker->contactKey('59671548000106', '59671548000');
        self::assertSame($a, $b);
    }

    public function testContratosDiferentesGeramChavesDiferentes(): void
    {
        $a = $this->checker->contactKey('59671548000106', '59671548000');
        $b = $this->checker->contactKey('45323997000159', '45323997000');
        self::assertNotSame($a, $b);
    }

    // ---------- Dedup de CONTATO duplicado no lote (Problema 2) ----------

    /**
     * Reproduz o caso do lote #1441: o MESMO contrato/CPF aparece em 2 leads
     * diferentes (contato duplicado). Resultado esperado: 1 envio (o 2º colapsa).
     */
    public function testMesmoContratoEmDoisLeadsColapsaParaUmEnvio(): void
    {
        // lead 52345 e lead 52346 → mesmo contrato 37186775000 / CPF 37186775000103
        $registros = [
            ['lead_id' => 52345, 'cpf' => '37186775000103', 'contract' => '37186775000'],
            ['lead_id' => 52346, 'cpf' => '37186775000103', 'contract' => '37186775000'],
        ];

        $seen      = [];
        $enviados  = [];
        $colapsados = [];

        foreach ($registros as $r) {
            $key = $this->checker->contactKey($r['cpf'], $r['contract']);
            if (isset($seen[$key])) {
                $colapsados[] = $r['lead_id'];
                continue;
            }
            $seen[$key]  = true;
            $enviados[]  = $r['lead_id'];
        }

        self::assertSame([52345], $enviados, 'Apenas o primeiro lead do contrato deve ser enviado');
        self::assertSame([52346], $colapsados, 'O lead duplicado deve ser colapsado/descartado');
    }

    public function testContratosDistintosNaoSaoColapsados(): void
    {
        // Dois contratos diferentes que por acaso compartilham um telefone NÃO devem
        // ser colapsados entre si (dedup é por contato/contrato, não por telefone).
        $registros = [
            ['cpf' => '37186775000103', 'contract' => '37186775000'],
            ['cpf' => '45323997000159', 'contract' => '45323997000'],
        ];

        $seen     = [];
        $enviados = 0;
        foreach ($registros as $r) {
            $key = $this->checker->contactKey($r['cpf'], $r['contract']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            ++$enviados;
        }

        self::assertSame(2, $enviados, 'Contratos distintos recebem cada um');
    }

    /**
     * Cobre o seed (itens já SENT/SENDING no lote): um contato já comprometido
     * não é reenviado quando seu duplicado aparece.
     */
    public function testContatoJaEnviadoNoLoteNaoEReenviado(): void
    {
        $seen = [];
        // Seed: contato já SENT
        $seen[$this->checker->contactKey('37186775000103', '37186775000')] = true;

        $novoDuplicado = $this->checker->contactKey('371.867.750.001-03', '37186775000');
        self::assertArrayHasKey($novoDuplicado, $seen, 'Duplicado do contato já enviado deve ser reconhecido pelo seed');
    }
}
