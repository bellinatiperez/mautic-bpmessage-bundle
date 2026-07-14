<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Entity representing per-supplier (fornecedor) API settings.
 *
 * @ORM\Entity
 *
 * @ORM\Table(name="plugin_fornecedor_settings")
 */
class PluginFornecedorSettings
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
     * @ORM\Column(name="id_carteira", type="string", length=191)
     */
    private string $idCarteira;

    /**
     * @ORM\Column(name="token_acesso", type="string", length=255)
     */
    private string $tokenAcesso;

    /**
     * @ORM\Column(name="id_empresa", type="string", length=191)
     */
    private string $idEmpresa;

    /**
     * @ORM\Column(name="url", type="string", length=255)
     */
    private string $url;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('plugin_fornecedor_settings');

        $builder->addId();

        $builder->addNamedField('idCarteira', 'string', 'id_carteira', ['length' => 191]);
        $builder->addNamedField('tokenAcesso', 'string', 'token_acesso', ['length' => 255]);
        $builder->addNamedField('idEmpresa', 'string', 'id_empresa', ['length' => 191]);
        $builder->addNamedField('url', 'string', 'url', ['length' => 255]);

        $builder->addIndex(['id_carteira'], 'idx_id_carteira');
        $builder->addIndex(['id_empresa'], 'idx_id_empresa');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdCarteira(): string
    {
        return $this->idCarteira;
    }

    public function setIdCarteira(string $idCarteira): self
    {
        $this->idCarteira = $idCarteira;

        return $this;
    }

    public function getTokenAcesso(): string
    {
        return $this->tokenAcesso;
    }

    public function setTokenAcesso(string $tokenAcesso): self
    {
        $this->tokenAcesso = $tokenAcesso;

        return $this;
    }

    public function getIdEmpresa(): string
    {
        return $this->idEmpresa;
    }

    public function setIdEmpresa(string $idEmpresa): self
    {
        $this->idEmpresa = $idEmpresa;

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
