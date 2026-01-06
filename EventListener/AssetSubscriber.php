<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber to inject BpMessage JavaScript assets.
 */
class AssetSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_ASSETS => ['onInjectCustomAssets', 0],
        ];
    }

    /**
     * Inject BpMessage routes JavaScript.
     */
    public function onInjectCustomAssets(CustomAssetsEvent $event): void
    {
        // Add the routes JavaScript to all pages (it will only activate when the form is present)
        $event->addScript('plugins/MauticBpMessageBundle/Assets/js/bpmessage-routes.js');
    }
}
