<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Entity representing a BpMessage batch/lot.
 *
 * @ORM\Entity
 *
 * @ORM\Table(name="bpmessage_lot")
 */
class BpMessageLot
{
    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue(strategy="AUTO")
     *
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(name="external_lot_id", type="string", length=255, nullable=true)
     */
    private ?string $externalLotId = null;

    /**
     * @ORM\Column(name="name", type="string", length=255)
     */
    private string $name;

    /**
     * @ORM\Column(name="start_date", type="datetime")
     */
    private \DateTime $startDate;

    /**
     * @ORM\Column(name="end_date", type="datetime")
     */
    private \DateTime $endDate;

    /**
     * @ORM\Column(name="user_cpf", type="string", length=14)
     */
    private string $userCpf;

    /**
     * @ORM\Column(name="id_quota_settings", type="integer")
     */
    private int $idQuotaSettings;

    /**
     * @ORM\Column(name="id_service_settings", type="integer")
     */
    private int $idServiceSettings;

    /**
     * @ORM\Column(name="service_type", type="integer", nullable=true)
     */
    private ?int $serviceType = null;

    /**
     * @ORM\Column(name="id_book_business_send_group", type="integer", nullable=true)
     */
    private ?int $idBookBusinessSendGroup = null;

    /**
     * @ORM\Column(name="book_business_foreign_id", type="string", length=255, nullable=true)
     */
    private ?string $bookBusinessForeignId = null;

    /**
     * @ORM\Column(name="image_url", type="text", nullable=true)
     */
    private ?string $imageUrl = null;

    /**
     * @ORM\Column(name="image_name", type="string", length=255, nullable=true)
     */
    private ?string $imageName = null;

    /**
     * @ORM\Column(name="status", type="string", length=20)
     */
    private string $status = 'CREATING';

    /**
     * @ORM\Column(name="messages_count", type="integer")
     */
    private int $messagesCount = 0;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTime $createdAt;

    /**
     * @ORM\Column(name="finished_at", type="datetime", nullable=true)
     */
    private ?\DateTime $finishedAt = null;

    /**
     * @ORM\Column(name="campaign_id", type="integer", nullable=true)
     */
    private ?int $campaignId = null;

    /**
     * @ORM\Column(name="api_base_url", type="string", length=255)
     */
    private string $apiBaseUrl;

    /**
     * @ORM\Column(name="batch_size", type="integer")
     */
    private int $batchSize = 1000;

    /**
     * @ORM\Column(name="time_window", type="integer")
     */
    private int $timeWindow = 300; // seconds

    /**
     * @ORM\Column(name="error_message", type="text", nullable=true)
     */
    private ?string $errorMessage = null;

    /**
     * @ORM\OneToMany(targetEntity="BpMessageQueue", mappedBy="lot", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    private Collection $queueItems;

    public function __construct()
    {
        $this->queueItems = new ArrayCollection();
        $this->createdAt  = new \DateTime();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('bpmessage_lot');

        $builder->addId();
        $builder->addField('externalLotId', 'string', ['nullable' => true, 'length' => 255]);
        $builder->addNamedField('name', 'string', 'name', ['length' => 255]);
        $builder->addNamedField('startDate', 'datetime', 'start_date');
        $builder->addNamedField('endDate', 'datetime', 'end_date');
        $builder->addNamedField('userCpf', 'string', 'user_cpf', ['length' => 14]);
        $builder->addNamedField('idQuotaSettings', 'integer', 'id_quota_settings');
        $builder->addNamedField('idServiceSettings', 'integer', 'id_service_settings');
        $builder->addNamedField('serviceType', 'integer', 'service_type', ['nullable' => true]);
        $builder->addNamedField('idBookBusinessSendGroup', 'integer', 'id_book_business_send_group', ['nullable' => true]);
        $builder->addNamedField('bookBusinessForeignId', 'string', 'book_business_foreign_id', ['nullable' => true, 'length' => 255]);
        $builder->addNamedField('imageUrl', 'text', 'image_url', ['nullable' => true]);
        $builder->addNamedField('imageName', 'string', 'image_name', ['nullable' => true, 'length' => 255]);
        $builder->addNamedField('status', 'string', 'status', ['length' => 20]);
        $builder->addNamedField('messagesCount', 'integer', 'messages_count');
        $builder->addNamedField('createdAt', 'datetime', 'created_at');
        $builder->addNamedField('finishedAt', 'datetime', 'finished_at', ['nullable' => true]);
        $builder->addNamedField('campaignId', 'integer', 'campaign_id', ['nullable' => true]);
        $builder->addNamedField('apiBaseUrl', 'string', 'api_base_url', ['length' => 255]);
        $builder->addNamedField('batchSize', 'integer', 'batch_size');
        $builder->addNamedField('timeWindow', 'integer', 'time_window');
        $builder->addNamedField('errorMessage', 'text', 'error_message', ['nullable' => true]);

        $builder->addIndex(['status'], 'idx_status');
        $builder->addIndex(['created_at'], 'idx_created_at');
        $builder->addIndex(['campaign_id'], 'idx_campaign_id');
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalLotId(): ?string
    {
        return $this->externalLotId;
    }

    public function setExternalLotId(?string $externalLotId): self
    {
        $this->externalLotId = $externalLotId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStartDate(): \DateTime
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTime $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): \DateTime
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTime $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getUserCpf(): string
    {
        return $this->userCpf;
    }

    public function setUserCpf(string $userCpf): self
    {
        $this->userCpf = $userCpf;

        return $this;
    }

    public function getIdQuotaSettings(): int
    {
        return $this->idQuotaSettings;
    }

    public function setIdQuotaSettings(int $idQuotaSettings): self
    {
        $this->idQuotaSettings = $idQuotaSettings;

        return $this;
    }

    public function getIdServiceSettings(): int
    {
        return $this->idServiceSettings;
    }

    public function setIdServiceSettings(int $idServiceSettings): self
    {
        $this->idServiceSettings = $idServiceSettings;

        return $this;
    }

    public function getServiceType(): ?int
    {
        return $this->serviceType;
    }

    public function setServiceType(?int $serviceType): self
    {
        $this->serviceType = $serviceType;

        return $this;
    }

    public function getIdBookBusinessSendGroup(): ?int
    {
        return $this->idBookBusinessSendGroup;
    }

    public function setIdBookBusinessSendGroup(?int $idBookBusinessSendGroup): self
    {
        $this->idBookBusinessSendGroup = $idBookBusinessSendGroup;

        return $this;
    }

    public function getBookBusinessForeignId(): ?string
    {
        return $this->bookBusinessForeignId;
    }

    public function setBookBusinessForeignId(?string $bookBusinessForeignId): self
    {
        $this->bookBusinessForeignId = $bookBusinessForeignId;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setImageName(?string $imageName): self
    {
        $this->imageName = $imageName;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getMessagesCount(): int
    {
        return $this->messagesCount;
    }

    public function setMessagesCount(int $messagesCount): self
    {
        $this->messagesCount = $messagesCount;

        return $this;
    }

    public function incrementMessagesCount(): self
    {
        ++$this->messagesCount;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTime
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTime $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getCampaignId(): ?int
    {
        return $this->campaignId;
    }

    public function setCampaignId(?int $campaignId): self
    {
        $this->campaignId = $campaignId;

        return $this;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function setApiBaseUrl(string $apiBaseUrl): self
    {
        $this->apiBaseUrl = $apiBaseUrl;

        return $this;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function setBatchSize(int $batchSize): self
    {
        $this->batchSize = $batchSize;

        return $this;
    }

    public function getTimeWindow(): int
    {
        return $this->timeWindow;
    }

    public function setTimeWindow(int $timeWindow): self
    {
        $this->timeWindow = $timeWindow;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getQueueItems(): Collection
    {
        return $this->queueItems;
    }

    public function addQueueItem(BpMessageQueue $queueItem): self
    {
        if (!$this->queueItems->contains($queueItem)) {
            $this->queueItems[] = $queueItem;
            $queueItem->setLot($this);
        }

        return $this;
    }

    public function removeQueueItem(BpMessageQueue $queueItem): self
    {
        if ($this->queueItems->removeElement($queueItem)) {
            if ($queueItem->getLot() === $this) {
                $queueItem->setLot(null);
            }
        }

        return $this;
    }

    /**
     * Check if lot should be closed based on time window.
     */
    public function shouldCloseByTime(): bool
    {
        $now     = new \DateTime();
        $elapsed = $now->getTimestamp() - $this->createdAt->getTimestamp();

        return $elapsed >= $this->timeWindow;
    }

    /**
     * Check if lot should be closed based on message count.
     */
    public function shouldCloseByCount(): bool
    {
        return $this->messagesCount >= $this->batchSize;
    }

    /**
     * Check if lot is open and accepting messages.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['CREATING', 'OPEN']);
    }

    /**
     * Check if lot is finished.
     */
    public function isFinished(): bool
    {
        return 'FINISHED' === $this->status;
    }

    /**
     * Check if lot has failed.
     */
    public function isFailed(): bool
    {
        return 'FAILED' === $this->status;
    }

    /**
     * Check if this is an email lot (idQuotaSettings = 0).
     * Email lots use idQuotaSettings = 0, while message lots (SMS/WhatsApp/RCS) use idQuotaSettings > 0.
     */
    public function isEmailLot(): bool
    {
        return 0 === $this->idQuotaSettings;
    }
}
