<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Entity representing a message in the queue to be sent
 *
 * @ORM\Entity
 * @ORM\Table(name="bpmessage_queue")
 */
class BpMessageQueue
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="BpMessageLot", inversedBy="queueItems")
     * @ORM\JoinColumn(name="lot_id", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    private ?BpMessageLot $lot = null;

    /**
     * @ORM\ManyToOne(targetEntity="Mautic\LeadBundle\Entity\Lead")
     * @ORM\JoinColumn(name="lead_id", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    private ?Lead $lead = null;

    /**
     * @ORM\Column(name="payload_json", type="text")
     */
    private string $payloadJson;

    /**
     * @ORM\Column(name="status", type="string", length=20)
     */
    private string $status = 'PENDING';

    /**
     * @ORM\Column(name="retry_count", type="smallint")
     */
    private int $retryCount = 0;

    /**
     * @ORM\Column(name="error_message", type="text", nullable=true)
     */
    private ?string $errorMessage = null;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTime $createdAt;

    /**
     * @ORM\Column(name="sent_at", type="datetime", nullable=true)
     */
    private ?\DateTime $sentAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('bpmessage_queue');

        $builder->addId();

        $builder->createManyToOne('lot', BpMessageLot::class)
            ->inversedBy('queueItems')
            ->addJoinColumn('lot_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->addNamedField('payloadJson', 'text', 'payload_json');
        $builder->addNamedField('status', 'string', 'status', ['length' => 20]);
        $builder->addNamedField('retryCount', 'smallint', 'retry_count');
        $builder->addNamedField('errorMessage', 'text', 'error_message', ['nullable' => true]);
        $builder->addNamedField('createdAt', 'datetime', 'created_at');
        $builder->addNamedField('sentAt', 'datetime', 'sent_at', ['nullable' => true]);

        $builder->addIndex(['lot_id', 'status'], 'idx_lot_status');
        $builder->addIndex(['created_at'], 'idx_created_at');
        $builder->addIndex(['status'], 'idx_status');
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLot(): ?BpMessageLot
    {
        return $this->lot;
    }

    public function setLot(?BpMessageLot $lot): self
    {
        $this->lot = $lot;
        return $this;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): self
    {
        $this->lead = $lead;
        return $this;
    }

    public function getPayloadJson(): string
    {
        return $this->payloadJson;
    }

    public function setPayloadJson(string $payloadJson): self
    {
        $this->payloadJson = $payloadJson;
        return $this;
    }

    public function getPayloadArray(): array
    {
        return json_decode($this->payloadJson, true) ?? [];
    }

    public function setPayloadArray(array $payload): self
    {
        $this->payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $retryCount): self
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function incrementRetryCount(): self
    {
        ++$this->retryCount;
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

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getSentAt(): ?\DateTime
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTime $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function isPending(): bool
    {
        return 'PENDING' === $this->status;
    }

    public function isSent(): bool
    {
        return 'SENT' === $this->status;
    }

    public function isFailed(): bool
    {
        return 'FAILED' === $this->status;
    }

    public function markAsSent(): self
    {
        $this->status = 'SENT';
        $this->sentAt = new \DateTime();
        return $this;
    }

    public function markAsFailed(string $errorMessage): self
    {
        $this->status = 'FAILED';
        $this->errorMessage = $errorMessage;
        $this->incrementRetryCount();
        return $this;
    }
}
