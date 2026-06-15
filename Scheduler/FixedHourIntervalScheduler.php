<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Scheduler;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Executioner\Scheduler\Mode\DAO\GroupExecutionDateDAO;
use Mautic\CampaignBundle\Executioner\Scheduler\Mode\Interval;
use Mautic\CoreBundle\Helper\DateTimeHelper;

/**
 * Override do scheduler de intervalo do core do Mautic.
 *
 * PROBLEMA (core): Interval::getExecutionDateTimeFromHour() só aplica o
 * trigger_hour quando a hora calculada (disparo do pai + intervalo) ainda NÃO
 * passou no dia calculado. Se já passou, devolve a hora calculada
 * ("Execute right away if the hour has passed"). Como a ação bpmessage.send
 * dispara ao longo do dia (ex.: 08:00–13:00), o vencimento do próximo estágio
 * cai depois do trigger_hour e o core devolve a hora do envio, espalhando os
 * vencimentos em vez de ancorá-los no trigger_hour.
 *
 * CORREÇÃO: delega 100% ao core e, quando o dia calculado é FUTURO (não hoje),
 * fixa a hora no trigger_hour do evento. Preserva o comportamento do core para
 * "hoje e a hora já passou" (executar agora) e para eventos sem trigger_hour.
 *
 * Registrado como substituição do serviço do core em Config/services.php, sem
 * editar o código vendored (sobrevive a upgrades do Mautic).
 */
class FixedHourIntervalScheduler extends Interval
{
    /**
     * {@inheritdoc}
     */
    public function groupContactsByDate(Event $event, ArrayCollection $contacts, \DateTimeInterface $executionDate, ?\DateTimeInterface $compareFromDateTime = null): array
    {
        $groups = parent::groupContactsByDate($event, $contacts, $executionDate, $compareFromDateTime);

        if (null === $event->getTriggerHour()) {
            return $groups;
        }

        // Re-agrupa pelas datas já ancoradas no trigger_hour (contatos que colidem
        // na mesma data/hora são unidos no mesmo grupo).
        $regrouped = [];

        foreach ($groups as $dao) {
            $pinnedDate = $this->pinToTriggerHourIfFuture($event, $dao->getExecutionDate());
            $key        = $pinnedDate->format(DateTimeHelper::FORMAT_DB);

            if (!isset($regrouped[$key])) {
                $regrouped[$key] = new GroupExecutionDateDAO($pinnedDate);
            }

            foreach ($dao->getContacts() as $contact) {
                $regrouped[$key]->addContact($contact);
            }
        }

        return $regrouped;
    }

    /**
     * {@inheritdoc}
     */
    public function validateExecutionDateTime(LeadEventLog $log, \DateTimeInterface $compareFromDateTime)
    {
        $date = parent::validateExecutionDateTime($log, $compareFromDateTime);

        return $this->pinToTriggerHourIfFuture($log->getEvent(), $date);
    }

    /**
     * Quando o dia calculado é futuro, fixa a hora no trigger_hour do evento.
     * Caso contrário (hoje/passado, ou sem trigger_hour) devolve a data inalterada,
     * preservando o comportamento do core ("executar agora se a hora já passou hoje").
     */
    private function pinToTriggerHourIfFuture(Event $event, \DateTimeInterface $date): \DateTimeInterface
    {
        $hour = $event->getTriggerHour();
        if (null === $hour) {
            return $date;
        }

        $pinned = ($date instanceof \DateTime) ? clone $date : \DateTime::createFromInterface($date);
        $now    = new \DateTime('now', $pinned->getTimezone());

        // Mantém o comportamento do core para hoje (e passado): só ancora a hora
        // quando a data calculada cai num dia FUTURO no mesmo fuso.
        if ($pinned->format('Y-m-d') <= $now->format('Y-m-d')) {
            return $date;
        }

        $pinned->setTime((int) $hour->format('H'), (int) $hour->format('i'));

        return $pinned;
    }
}
