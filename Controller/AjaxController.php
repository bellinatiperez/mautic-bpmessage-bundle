<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use MauticPlugin\MauticBpMessageBundle\Service\RoutesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX Controller for BpMessage plugin.
 *
 * This controller is called via Mautic's centralized AJAX system.
 * Actions are invoked via: /s/ajax?action=plugin:BpMessage:actionName
 */
class AjaxController extends CommonAjaxController
{
    /**
     * Get available routes for the given parameters.
     *
     * Called via: /s/ajax?action=plugin:BpMessage:getRoutes
     *
     * Uses method injection for RoutesService (Symfony autowiring).
     *
     * @return JsonResponse List of routes or error
     */
    public function getRoutesAction(Request $request, RoutesService $routesService): JsonResponse
    {
        $bookBusinessForeignId = $request->query->getInt('book_business_foreign_id', 0);
        $crmId                 = $request->query->getInt('crm_id', 0);
        $serviceType           = $request->query->getInt('service_type', 1);

        // Also check POST data (Mautic AJAX can send via POST)
        if (0 === $bookBusinessForeignId) {
            $bookBusinessForeignId = $request->request->getInt('book_business_foreign_id', 0);
        }
        if (0 === $crmId) {
            $crmId = $request->request->getInt('crm_id', 0);
        }
        if (1 === $serviceType && $request->request->has('service_type')) {
            $serviceType = $request->request->getInt('service_type', 1);
        }

        // Validate required parameters
        if (0 === $bookBusinessForeignId || 0 === $crmId) {
            return $this->sendJsonResponse([
                'success' => false,
                'error'   => 'Missing required parameters: book_business_foreign_id and crm_id are required',
                'routes'  => [],
            ]);
        }

        try {
            $routes = $routesService->getRoutes($bookBusinessForeignId, $crmId, $serviceType);

            // Check if routes is empty (could be API error or no routes available)
            if (empty($routes)) {
                return $this->sendJsonResponse([
                    'success' => false,
                    'error'   => 'Nenhuma rota encontrada para os parametros informados. Verifique se o plugin BpMessage esta configurado corretamente.',
                    'routes'  => [],
                    'debug'   => [
                        'book_business_foreign_id' => $bookBusinessForeignId,
                        'crm_id'                   => $crmId,
                        'service_type'             => $serviceType,
                    ],
                ]);
            }

            // Format routes for select dropdown
            $formattedRoutes = array_map(function (array $route) {
                return [
                    'id'              => $route['idServiceSettings'] ?? 0,
                    'name'            => $route['name'] ?? 'Unknown',
                    'provider'        => $route['provider'] ?? '',
                    'price'           => $route['price'] ?? 0,
                    'quota'           => $route['quota'] ?? 0,
                    'available'       => $route['available'] ?? 0,
                    'defaultService'  => $route['defaultService'] ?? false,
                    'useTemplate'     => $route['useTemplate'] ?? false,
                    'idQuotaSettings' => $route['idQuotaSettings'] ?? 0,
                    // Formatted label for display
                    'label' => sprintf(
                        '%s - %s (R$ %.2f)',
                        $route['name'] ?? 'Unknown',
                        $route['provider'] ?? '',
                        $route['price'] ?? 0
                    ),
                ];
            }, $routes);

            return $this->sendJsonResponse([
                'success' => true,
                'routes'  => $formattedRoutes,
                'error'   => null,
            ]);
        } catch (\Exception $e) {
            return $this->sendJsonResponse([
                'success' => false,
                'error'   => 'Erro ao buscar rotas: '.$e->getMessage(),
                'routes'  => [],
            ]);
        }
    }
}
