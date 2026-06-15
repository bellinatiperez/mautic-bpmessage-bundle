<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Tests\Scheduler;

use Mautic\CampaignBundle\Entity\Event;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticBpMessageBundle\Scheduler\FixedHourIntervalScheduler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FixedHourIntervalSchedulerTest extends TestCase
{
    private const TZ = 'America/Sao_Paulo';

    private function scheduler(): FixedHourIntervalScheduler
    {
        $coreParams = $this->createMock(CoreParametersHelper::class);
        $coreParams->method('getDefaultTimezone')->willReturn(self::TZ);

        return new FixedHourIntervalScheduler(new NullLogger(), $coreParams);
    }

    private function pin(Event $event, \DateTimeInterface $date): \DateTimeInterface
    {
        $method = new \ReflectionMethod(FixedHourIntervalScheduler::class, 'pinToTriggerHourIfFuture');
        $method->setAccessible(true);

        return $method->invoke($this->scheduler(), $event, $date);
    }

    private function eventWithTriggerHour(?string $hour): Event
    {
        $event = $this->createMock(Event::class);
        $event->method('getTriggerHour')->willReturn(null === $hour ? null : new \DateTime($hour));

        return $event;
    }

    public function testFutureDayIsPinnedToTriggerHour(): void
    {
        // Cenário do bug: pai disparou às 16:20, próximo vencimento calculado amanhã 16:20.
        $date = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('+1 day')->setTime(16, 20);

        $result = $this->pin($this->eventWithTriggerHour('08:00'), $date);

        self::assertSame('08:00', $result->format('H:i'), 'Dia futuro deve ser ancorado no trigger_hour.');
        self::assertSame($date->format('Y-m-d'), $result->format('Y-m-d'), 'O dia não deve mudar.');
    }

    public function testTodayKeepsCoreBehaviour(): void
    {
        // Hoje com a hora já passada: preserva o "executar agora" do core.
        $date = (new \DateTime('now', new \DateTimeZone(self::TZ)))->setTime(16, 20);

        $result = $this->pin($this->eventWithTriggerHour('08:00'), $date);

        self::assertEquals($date, $result, 'Hoje (hora já passou) deve manter o comportamento do core.');
    }

    public function testEventWithoutTriggerHourIsUnchanged(): void
    {
        $date = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('+2 days')->setTime(16, 20);

        $result = $this->pin($this->eventWithTriggerHour(null), $date);

        self::assertEquals($date, $result, 'Sem trigger_hour não deve haver alteração.');
    }

    public function testFutureDayPreservesDayOfWeek(): void
    {
        // Garante que ancorar a hora não muda o dia (logo, não viola o dow já aplicado pelo core).
        $date   = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('+3 days')->setTime(12, 34);
        $result = $this->pin($this->eventWithTriggerHour('08:00'), $date);

        self::assertSame($date->format('w'), $result->format('w'), 'O dia da semana deve ser preservado.');
        self::assertSame('08:00', $result->format('H:i'));
    }
}
