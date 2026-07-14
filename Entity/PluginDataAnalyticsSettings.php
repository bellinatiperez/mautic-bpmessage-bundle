<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Entity representing data analytics integration settings.
 *
 * @ORM\Entity
 *
 * @ORM\Table(name="plugin_dataAnalytics_settings")
 */
class PluginDataAnalyticsSettings
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
     * @ORM\Column(name="nome", type="string", length=191)
     */
    private string $nome;

    /**
     * @ORM\Column(name="token", type="string", length=255)
     */
    private string $token;

    /**
     * @ORM\Column(name="url", type="string", length=255)
     */
    private string $url;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('plugin_dataAnalytics_settings');

        $builder->addId();

        $builder->addNamedField('nome', 'string', 'nome', ['length' => 191]);
        $builder->addNamedField('token', 'string', 'token', ['length' => 255]);
        $builder->addNamedField('url', 'string', 'url', ['length' => 255]);

        $builder->addIndex(['nome'], 'idx_nome');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): self
    {
        $this->nome = $nome;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }
}
