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
        // Get values as strings - do NOT convert to int (preserve leading zeros, alphanumeric values like "C0001")
        $bookBusinessForeignId = trim((string) $request->query->get('book_business_foreign_id', ''));
        $crmId                 = trim((string) $request->query->get('crm_id', ''));
        $serviceType           = (int) $request->query->get('service_type', 1) ?: 1; // Only service_type is int

        // Also check POST data (Mautic AJAX can send via POST)
        if ('' === $bookBusinessForeignId && $request->request->has('book_business_foreign_id')) {
            $bookBusinessForeignId = trim((string) $request->request->get('book_business_foreign_id', ''));
        }
        if ('' === $crmId && $request->request->has('crm_id')) {
            $crmId = trim((string) $request->request->get('crm_id', ''));
        }
        if (1 === $serviceType && $request->request->has('service_type')) {
            $serviceType = (int) $request->request->get('service_type', 1) ?: 1;
        }

        // Validate required parameters (check for empty strings)
        if ('' === $bookBusinessForeignId || '' === $crmId) {
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
