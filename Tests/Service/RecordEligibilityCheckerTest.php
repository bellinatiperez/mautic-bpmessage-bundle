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

    // ---------- Entregável canônico do payload ----------

    public function testDeliverablePreferiEmailQuandoPresente(): void
    {
        self::assertSame('e:joao@exemplo.com', $this->checker->deliverableFromPayload(['to' => '  JOAO@Exemplo.com ']));
    }

    public function testDeliverableTelefoneSoDigitosDeAreaCodeEPhone(): void
    {
        self::assertSame('p:11947430156', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '94743-0156']));
    }

    public function testDeliverableVazioQuandoSemEntregavel(): void
    {
        self::assertSame('', $this->checker->deliverableFromPayload([]));
        self::assertSame('', $this->checker->deliverableFromPayload(['areaCode' => '', 'phone' => '']));
    }

    // ---------- Dedup de ENTREGA: contrato + telefone/e-mail ----------

    public function testDedupKeyNullSemContratoOuSemEntregavel(): void
    {
        self::assertNull($this->checker->dedupKey('', 'p:11999999999'));   // sem contrato → não deduplica
        self::assertNull($this->checker->dedupKey(null, 'p:11999999999'));
        self::assertNull($this->checker->dedupKey('59671548000', ''));     // sem entregável → não deduplica
    }

    public function testMesmoContratoMesmoTelefoneMesmaChave(): void
    {
        $a = $this->checker->dedupKey('37186775000', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '947430156']));
        $b = $this->checker->dedupKey('37186775000', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '94743-0156']));
        self::assertNotNull($a);
        self::assertSame($a, $b, 'Mesmo contrato + mesmo telefone (formatação irrelevante) = mesma chave');
    }

    public function testMesmoContratoTelefonesDiferentesChavesDiferentes(): void
    {
        // NÚCLEO da correção do lote #1474: telefones DIFERENTES do MESMO contrato
        // são entregas distintas e NÃO podem colapsar.
        $a = $this->checker->dedupKey('02936673000', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '947430156']));
        $b = $this->checker->dedupKey('02936673000', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '947428906']));
        self::assertNotSame($a, $b, 'Telefones diferentes do mesmo contrato devem gerar chaves diferentes (ambos enviam)');
    }

    public function testContratosDiferentesMesmoTelefoneChavesDiferentes(): void
    {
        $a = $this->checker->dedupKey('11111111111', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '999999999']));
        $b = $this->checker->dedupKey('22222222222', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '999999999']));
        self::assertNotSame($a, $b, 'Contratos diferentes que compartilham telefone recebem cada um');
    }

    /**
     * Reproduz o lote #1474: contrato com 3 entregas — 2 telefones iguais
     * (devem colapsar p/ 1) + 1 telefone distinto (deve seguir). Resultado: 2 envios.
     */
    public function testLote1474MesmoContratoColapsaSoTelefoneIdentico(): void
    {
        $registros = [
            ['contract' => '02936673000', 'areaCode' => '11', 'phone' => '947430156'], // A
            ['contract' => '02936673000', 'areaCode' => '11', 'phone' => '947428906'], // B (distinto)
            ['contract' => '02936673000', 'areaCode' => '11', 'phone' => '947430156'], // A' (duplicado de A)
        ];

        $seen      = [];
        $enviados  = [];
        $colapsados = [];
        foreach ($registros as $i => $r) {
            $key = $this->checker->dedupKey($r['contract'], $this->checker->deliverableFromPayload($r));
            if (null !== $key && isset($seen[$key])) {
                $colapsados[] = $i;
                continue;
            }
            if (null !== $key) {
                $seen[$key] = true;
            }
            $enviados[] = $i;
        }

        self::assertSame([0, 1], $enviados, 'Telefone distinto (B) deve ser enviado junto com A; só o 3º (igual a A) colapsa');
        self::assertSame([2], $colapsados, 'Apenas a entrega idêntica (mesmo contrato + mesmo telefone) é colapsada');
    }

    public function testEntregaJaEnviadaNoLoteReconhecidaPeloSeed(): void
    {
        $seen = [];
        // Seed: entrega já SENT (contrato + telefone)
        $seedKey = $this->checker->dedupKey('37186775000', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '947430156']));
        $seen[$seedKey] = true;

        $novoDuplicado = $this->checker->dedupKey('37186775000', $this->checker->deliverableFromPayload(['areaCode' => '11', 'phone' => '94743-0156']));
        self::assertArrayHasKey($novoDuplicado, $seen, 'Duplicado exato (mesmo contrato+telefone) já enviado deve ser reconhecido pelo seed');
    }

    public function testEmailMesmoContratoEmailsDiferentesNaoColapsam(): void
    {
        $a = $this->checker->dedupKey('99999999000', $this->checker->deliverableFromPayload(['to' => 'a@x.com']));
        $b = $this->checker->dedupKey('99999999000', $this->checker->deliverableFromPayload(['to' => 'b@x.com']));
        self::assertNotSame($a, $b, 'E-mails diferentes do mesmo contrato são entregas distintas');
    }
}
