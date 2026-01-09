/**
 * BpMessage Routes - Dynamic route loading via AJAX
 *
 * This script handles the dynamic loading of routes for the BpMessage action form.
 * When service_type, crm_id, or book_business_foreign_id changes, it fetches
 * available routes from the API and populates the route select dropdown.
 *
 * Compatible with Mautic's Chosen.js select components.
 */
(function () {
    'use strict';

    // Store the current selected value to restore after reload
    var currentSelectedValue = null;
    var isLoading = false;
    var routesLoaded = false;
    var lastParams = null;
    var dropdownOpenedOnce = false;

    /**
     * Find the route select element
     * Prioritizes visible/active modals
     */
    function findRouteSelect() {
        // First try to find in visible modal
        var activeModal = document.querySelector('.modal.in, .modal.show, .builder-content');
        if (activeModal) {
            var selectInModal = activeModal.querySelector('[data-bpmessage-routes-select]');
            if (selectInModal) {
                return selectInModal;
            }
        }

        // Fallback to any route select
        return document.querySelector('[data-bpmessage-routes-select]');
    }

    /**
     * Find the route data hidden field (stores full route object as JSON)
     * Prioritizes fields within the same context as the route select
     */
    function findRouteDataField() {
        var routeSelect = document.querySelector('[data-bpmessage-routes-select]');
        var searchContext = document;

        // First try to find in visible modal
        var activeModal = document.querySelector('.modal.in, .modal.show, .builder-content');
        if (activeModal) {
            searchContext = activeModal;
        } else if (routeSelect) {
            var form = routeSelect.closest('form');
            if (form) {
                searchContext = form;
            }
        }

        var field = searchContext.querySelector('[data-bpmessage-route-data]');
        if (field) return field;

        field = searchContext.querySelector('input[name*="[route_data]"]');
        if (field) return field;

        field = searchContext.querySelector('input[id*="_route_data"]');
        if (field) return field;

        // Fallback to document-wide search
        if (searchContext !== document) {
            field = document.querySelector('[data-bpmessage-route-data]');
            if (field) return field;

            field = document.querySelector('input[name*="[route_data]"]');
            if (field) return field;

            field = document.querySelector('input[id*="_route_data"]');
            if (field) return field;
        }

        return null;
    }

    /**
     * Get saved route data from hidden field
     */
    function getSavedRouteData() {
        var field = findRouteDataField();
        if (field && field.value) {
            try {
                return JSON.parse(field.value);
            } catch (e) {
                // Ignore parse errors
            }
        }
        return null;
    }

    /**
     * Save route data to hidden field
     */
    function saveRouteData(routeData) {
        var field = findRouteDataField();
        if (field) {
            field.value = JSON.stringify(routeData);
        }
    }

    /**
     * Synchronize route_data hidden field with current select value
     */
    function syncRouteDataFromSelect(select) {
        if (!select) {
            select = findRouteSelect();
        }
        if (!select || !select.value) {
            return;
        }

        currentSelectedValue = select.value;

        var selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            var routeData = {
                id: parseInt(select.value) || select.value,
                label: selectedOption.text,
                provider: selectedOption.dataset.provider || '',
                price: parseFloat(selectedOption.dataset.price) || 0,
                quota: parseInt(selectedOption.dataset.quota) || 0,
                available: parseInt(selectedOption.dataset.available) || 0,
                useTemplate: selectedOption.dataset.useTemplate === '1',
                idQuotaSettings: parseInt(selectedOption.dataset.idQuotaSettings) || 0
            };
            saveRouteData(routeData);
        }
    }

    /**
     * Find trigger fields (service_type, crm_id, book_business_foreign_id)
     * Prioritizes fields within the same form/modal as the route select
     */
    function findTriggerFields() {
        var routeSelect = findRouteSelect();
        var searchContext = document;

        // If we have a route select, search within its form or closest modal first
        if (routeSelect) {
            var form = routeSelect.closest('form');
            var modal = routeSelect.closest('.modal, .builder-content, [id*="campaignevent"]');
            searchContext = form || modal || document;
        }

        var fields = searchContext.querySelectorAll('[data-bpmessage-routes-trigger]');

        // Fallback to document-wide search if not found in context
        if (fields.length === 0 && searchContext !== document) {
            fields = document.querySelectorAll('[data-bpmessage-routes-trigger]');
        }

        return fields;
    }

    /**
     * Find a field by partial name match
     * Prioritizes fields within the same form/modal as the route select
     */
    function findFieldByName(partialName) {
        var routeSelect = findRouteSelect();
        var searchContext = document;

        // If we have a route select, search within its form or closest modal first
        if (routeSelect) {
            var form = routeSelect.closest('form');
            var modal = routeSelect.closest('.modal, .builder-content, [id*="campaignevent"]');
            searchContext = form || modal || document;
        }

        // First, try to find by the data-bpmessage-routes-trigger attribute with matching name
        var triggerFields = searchContext.querySelectorAll('[data-bpmessage-routes-trigger]');
        for (var i = 0; i < triggerFields.length; i++) {
            var field = triggerFields[i];
            var fieldName = field.name || '';
            var fieldId = field.id || '';
            if (fieldName.indexOf('[' + partialName + ']') !== -1 ||
                fieldName.endsWith('[' + partialName + ']') ||
                fieldId.indexOf('_' + partialName) !== -1 ||
                fieldId.endsWith('_' + partialName)) {
                return field;
            }
        }

        var selectors = [
            '[name*="[' + partialName + ']"]',
            '[name$="[' + partialName + ']"]',
            '[id*="_' + partialName + '"]',
            '[id$="_' + partialName + '"]'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var field = searchContext.querySelector(selectors[i]);
            if (field) {
                return field;
            }
        }

        // Fallback to document-wide search if not found in context
        if (searchContext !== document) {
            for (var i = 0; i < selectors.length; i++) {
                var field = document.querySelector(selectors[i]);
                if (field) {
                    return field;
                }
            }
        }

        return null;
    }

    /**
     * Update Chosen component after changing select options
     */
    function updateChosenComponent(select, retryCount, skipRecreate) {
        if (!select) return;
        retryCount = retryCount || 0;

        if (typeof jQuery !== 'undefined') {
            var $select = jQuery(select);
            var $chosenContainer = $select.next('.chosen-container');

            if (!$chosenContainer.length && retryCount < 5) {
                setTimeout(function() {
                    updateChosenComponent(select, retryCount + 1, skipRecreate);
                }, 200);
                return;
            }

            if ($chosenContainer.length) {
                if (skipRecreate) {
                    $select.trigger('chosen:updated');
                } else {
                    $select.off('chosen:showing_dropdown.bpmessage');

                    $select.chosen('destroy');
                    $select.chosen({
                        width: '100%',
                        allow_single_deselect: true,
                        search_contains: true
                    });
                }
            }
        }
    }

    /**
     * Get current params as string for comparison
     */
    function getCurrentParams() {
        var serviceTypeField = findFieldByName('service_type');
        var crmIdField = findFieldByName('crm_id');
        var bookBusinessField = findFieldByName('book_business_foreign_id');

        if (!serviceTypeField || !crmIdField || !bookBusinessField) {
            return null;
        }

        var serviceType = String(serviceTypeField.value || '').trim();
        var crmId = String(crmIdField.value || '').trim();
        var bookBusinessForeignId = String(bookBusinessField.value || '').trim();

        // Return null if any required value is missing or zero
        if (!serviceType || !crmId || !bookBusinessForeignId || crmId === '0' || bookBusinessForeignId === '0') {
            return null;
        }

        return serviceType + '_' + crmId + '_' + bookBusinessForeignId;
    }

    /**
     * Initialize the routes functionality
     */
    function initRoutes() {
        var routeSelect = findRouteSelect();
        var triggerFields = findTriggerFields();

        console.log('BpMessage initRoutes:', {
            routeSelect: routeSelect ? routeSelect.id : 'not found',
            triggerFieldsCount: triggerFields.length,
            triggerFieldIds: Array.from(triggerFields).map(function(f) { return f.id + ' type=' + f.type; }),
            allTriggerFieldsInDoc: document.querySelectorAll('[data-bpmessage-routes-trigger]').length
        });

        if (!routeSelect) {
            console.log('BpMessage: No route select found, exiting initRoutes');
            return;
        }

        if (triggerFields.length === 0) {
            console.log('BpMessage: No trigger fields found, exiting initRoutes');
            return;
        }

        // Add listeners for form submit
        var form = routeSelect.closest('form');
        if (form && !form.dataset.bpmessageSubmitListener) {
            form.dataset.bpmessageSubmitListener = 'true';

            var ensureRouteDataSaved = function() {
                syncRouteDataFromSelect();
            };

            form.addEventListener('submit', ensureRouteDataSaved);

            var saveButtons = form.querySelectorAll('[type="submit"], .btn-save, .btn-apply, [name*="buttons[save]"], [name*="buttons[apply]"]');
            saveButtons.forEach(function(btn) {
                btn.addEventListener('click', ensureRouteDataSaved);
            });

            if (typeof jQuery !== 'undefined') {
                jQuery(form).on('ajaxSend', ensureRouteDataSaved);

                jQuery(document).on('mauticFormPre', function() {
                    ensureRouteDataSaved();
                });
            }
        }

        var hasInitialValue = false;
        var savedRouteData = getSavedRouteData();

        var initialRouteJson = routeSelect.dataset.initialRoute || routeSelect.getAttribute('data-initial-route');
        var initialRouteData = savedRouteData;

        if (!initialRouteData && initialRouteJson) {
            try {
                initialRouteData = JSON.parse(initialRouteJson);
                saveRouteData(initialRouteData);
            } catch (e) {
                // Ignore parse errors
            }
        }

        if (!currentSelectedValue) {
            if (routeSelect.value && routeSelect.value !== '') {
                currentSelectedValue = routeSelect.value;
                hasInitialValue = true;
            } else {
                var initialValue = routeSelect.dataset.initialValue || routeSelect.getAttribute('data-initial-value');
                if (initialValue) {
                    currentSelectedValue = initialValue;
                    hasInitialValue = true;
                }
            }
        } else {
            hasInitialValue = true;
        }

        var hasValidRouteData = initialRouteData && initialRouteData.label && initialRouteData.id;
        if (hasInitialValue && hasValidRouteData) {
            routesLoaded = true;
            lastParams = getCurrentParams();
            updateChosenComponent(routeSelect);
        }

        // Add change listeners to trigger fields
        triggerFields.forEach(function (field) {
            field.removeEventListener('change', handleTriggerFieldChange);
            field.removeEventListener('blur', handleTriggerFieldBlur);
            field.removeEventListener('input', handleTriggerFieldInput);

            field.addEventListener('change', handleTriggerFieldChange);
            field.addEventListener('blur', handleTriggerFieldBlur);

            // For text/number inputs, also listen to 'input' event for real-time updates
            if (field.tagName === 'INPUT' && (field.type === 'text' || field.type === 'number')) {
                field.addEventListener('input', handleTriggerFieldInput);
            }

            // For SELECT elements, also bind jQuery change event for Chosen.js compatibility
            // Chosen.js triggers jQuery change event, not native DOM change event
            if (field.tagName === 'SELECT' && typeof jQuery !== 'undefined') {
                var $field = jQuery(field);
                // Remove any previous handler to avoid duplicates
                $field.off('change.bpmessage');
                // Bind jQuery change event - this is triggered by Chosen.js when selection changes
                $field.on('change.bpmessage', function() {
                    console.log('BpMessage: Chosen select changed via jQuery:', field.id, field.value);
                    handleTriggerFieldChange.call(field);
                });
            }
        });

        // Listen to route select change
        routeSelect.removeEventListener('change', handleRouteSelectChange);
        routeSelect.addEventListener('change', handleRouteSelectChange);

        // Listen to Chosen dropdown opening
        if (typeof jQuery !== 'undefined') {
            var $routeSelect = jQuery(routeSelect);
            $routeSelect.off('chosen:showing_dropdown.bpmessage');
            $routeSelect.on('chosen:showing_dropdown.bpmessage', function() {
                if (!isLoading && !dropdownOpenedOnce) {
                    dropdownOpenedOnce = true;
                    routesLoaded = false;
                    loadRoutes();
                }
            });
        }

        var params = getCurrentParams();
        var needsImmediateLoad = false;

        if (!hasInitialValue) {
            needsImmediateLoad = !!params;
        } else if (!hasValidRouteData) {
            needsImmediateLoad = !!params;
        }

        if (needsImmediateLoad) {
            loadRoutesIfNeeded();
        }
    }

    /**
     * Handle route select change
     */
    function handleRouteSelectChange(e) {
        var select = e.target;
        syncRouteDataFromSelect(select);
    }

    /**
     * Handle trigger field change
     */
    function handleTriggerFieldChange() {
        var newParams = getCurrentParams();
        // Always reset and reload when trigger field changes
        routesLoaded = false;
        dropdownOpenedOnce = false;
        lastParams = null; // Force reload
        currentSelectedValue = null; // Clear previous selection
        loadRoutes();
    }

    /**
     * Handle trigger field blur
     */
    function handleTriggerFieldBlur() {
        var newParams = getCurrentParams();
        if (newParams !== lastParams && newParams !== null) {
            routesLoaded = false;
            dropdownOpenedOnce = false;
            lastParams = null; // Force reload
            loadRoutes();
        }
    }

    /**
     * Debounce timer for input events
     */
    var inputDebounceTimer = null;

    /**
     * Handle trigger field input (for text/number fields - real-time updates with debounce)
     */
    function handleTriggerFieldInput() {
        // Clear previous timer
        if (inputDebounceTimer) {
            clearTimeout(inputDebounceTimer);
        }

        // Set new timer - wait 500ms after user stops typing
        inputDebounceTimer = setTimeout(function() {
            var newParams = getCurrentParams();
            console.log('BpMessage input event - params:', newParams, 'lastParams:', lastParams);
            if (newParams !== lastParams && newParams !== null) {
                routesLoaded = false;
                dropdownOpenedOnce = false;
                lastParams = null; // Force reload
                currentSelectedValue = null; // Clear previous selection
                loadRoutes();
            }
        }, 500);
    }

    /**
     * Load routes only if needed
     */
    function loadRoutesIfNeeded() {
        var newParams = getCurrentParams();

        if (routesLoaded && newParams === lastParams) {
            return;
        }

        loadRoutes();
    }

    /**
     * Load routes from the API
     */
    function loadRoutes() {
        if (isLoading) {
            return;
        }

        var serviceTypeField = findFieldByName('service_type');
        var crmIdField = findFieldByName('crm_id');
        var bookBusinessField = findFieldByName('book_business_foreign_id');
        var routeSelect = findRouteSelect();

        if (!serviceTypeField || !crmIdField || !bookBusinessField || !routeSelect) {
            return;
        }

        var serviceType = serviceTypeField.value;
        var crmId = crmIdField.value;
        var bookBusinessForeignId = bookBusinessField.value;

        // Trim values and convert to ensure proper comparison
        serviceType = String(serviceType || '').trim();
        crmId = String(crmId || '').trim();
        bookBusinessForeignId = String(bookBusinessForeignId || '').trim();

        // Debug log
        var routeSelectForDebug = findRouteSelect();
        var searchContextDebug = 'document';
        if (routeSelectForDebug) {
            var formDebug = routeSelectForDebug.closest('form');
            var modalDebug = routeSelectForDebug.closest('.modal, .builder-content, [id*="campaignevent"]');
            searchContextDebug = formDebug ? 'form#' + (formDebug.id || 'no-id') : (modalDebug ? 'modal/builder' : 'document');
        }
        console.log('BpMessage loadRoutes:', {
            serviceType: serviceType,
            crmId: crmId,
            bookBusinessForeignId: bookBusinessForeignId,
            serviceTypeField: serviceTypeField ? (serviceTypeField.id + ' name=' + serviceTypeField.name) : 'not found',
            crmIdField: crmIdField ? (crmIdField.id + ' name=' + crmIdField.name) : 'not found',
            bookBusinessField: bookBusinessField ? (bookBusinessField.id + ' name=' + bookBusinessField.name) : 'not found',
            searchContext: searchContextDebug,
            rawValues: {
                serviceTypeRaw: serviceTypeField ? serviceTypeField.value : null,
                crmIdRaw: crmIdField ? crmIdField.value : null,
                bookBusinessRaw: bookBusinessField ? bookBusinessField.value : null
            }
        });

        if (!serviceType || !crmId || !bookBusinessForeignId || crmId === '0' || bookBusinessForeignId === '0') {
            console.log('BpMessage: Missing params, clearing select');
            clearRouteSelect(routeSelect);
            return;
        }

        lastParams = serviceType + '_' + crmId + '_' + bookBusinessForeignId;
        isLoading = true;

        routeSelect.innerHTML = '<option value="">Carregando rotas...</option>';
        updateChosenComponent(routeSelect, 0, true);

        var baseUrl = typeof mauticBaseUrl !== 'undefined' ? mauticBaseUrl : '';
        if (baseUrl && !baseUrl.endsWith('/')) {
            baseUrl += '/';
        }

        var url = baseUrl + 's/ajax?' +
            'action=plugin:BpMessage:getRoutes' +
            '&service_type=' + encodeURIComponent(serviceType) +
            '&crm_id=' + encodeURIComponent(crmId) +
            '&book_business_foreign_id=' + encodeURIComponent(bookBusinessForeignId);

        console.log('BpMessage API URL:', url);

        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            isLoading = false;
            routesLoaded = true;

            console.log('BpMessage API response:', data);

            if (data.success && data.routes && data.routes.length > 0) {
                populateRouteSelect(routeSelect, data.routes);
            } else {
                var errorMsg = data.error || 'Nenhuma rota encontrada';
                console.log('BpMessage API error:', errorMsg);
                routeSelect.innerHTML = '<option value="">(' + errorMsg + ')</option>';
                updateChosenComponent(routeSelect, 0, true);
            }
        })
        .catch(function (error) {
            isLoading = false;
            console.log('BpMessage fetch error:', error);
            routeSelect.innerHTML = '<option value="">(Erro ao carregar rotas)</option>';
            updateChosenComponent(routeSelect, 0, true);
        });
    }

    /**
     * Populate the route select with options
     */
    function populateRouteSelect(select, routes) {
        select.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Selecione uma rota...';
        select.appendChild(placeholder);

        routes.forEach(function (route) {
            var option = document.createElement('option');
            option.value = route.id;
            option.textContent = route.label;

            option.dataset.provider = route.provider || '';
            option.dataset.price = route.price || 0;
            option.dataset.quota = route.quota || 0;
            option.dataset.available = route.available || 0;
            option.dataset.useTemplate = route.useTemplate ? '1' : '0';
            option.dataset.idQuotaSettings = route.idQuotaSettings || 0;

            if (route.defaultService) {
                option.textContent += ' (Padrao)';
            }

            select.appendChild(option);
        });

        var valueRestored = false;
        if (currentSelectedValue) {
            var matchingRoute = routes.find(function (route) {
                return String(route.id) === String(currentSelectedValue);
            });

            if (matchingRoute) {
                select.value = currentSelectedValue;
                valueRestored = true;
                var routeData = {
                    id: parseInt(matchingRoute.id) || matchingRoute.id,
                    label: matchingRoute.label + (matchingRoute.defaultService ? ' (Padrao)' : ''),
                    provider: matchingRoute.provider || '',
                    price: parseFloat(matchingRoute.price) || 0,
                    quota: parseInt(matchingRoute.quota) || 0,
                    available: parseInt(matchingRoute.available) || 0,
                    useTemplate: matchingRoute.useTemplate || false,
                    idQuotaSettings: parseInt(matchingRoute.idQuotaSettings) || 0
                };
                saveRouteData(routeData);
            }
        }

        if (!valueRestored && !select.value) {
            var defaultRoute = routes.find(function (route) {
                return route.defaultService;
            });

            if (defaultRoute) {
                select.value = defaultRoute.id;
                currentSelectedValue = String(defaultRoute.id);
                var routeData = {
                    id: parseInt(defaultRoute.id) || defaultRoute.id,
                    label: defaultRoute.label + ' (Padrao)',
                    provider: defaultRoute.provider || '',
                    price: parseFloat(defaultRoute.price) || 0,
                    quota: parseInt(defaultRoute.quota) || 0,
                    available: parseInt(defaultRoute.available) || 0,
                    useTemplate: defaultRoute.useTemplate || false,
                    idQuotaSettings: parseInt(defaultRoute.idQuotaSettings) || 0
                };
                saveRouteData(routeData);
            }
        }

        updateChosenComponent(select);
    }

    /**
     * Clear the route select
     */
    function clearRouteSelect(select) {
        select.innerHTML = '<option value="">Preencha CRM e Carteira para carregar rotas</option>';
        routesLoaded = false;
        lastParams = null;
        dropdownOpenedOnce = false;
        updateChosenComponent(select, 0, true);
    }

    /**
     * Initialize when DOM is ready
     */
    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    // Initialize on DOM ready
    onReady(function() {
        setTimeout(initRoutes, 500);
    });

    // Re-initialize when Mautic loads new content
    if (typeof Mautic !== 'undefined') {
        var originalPageLoad = Mautic.onPageLoad || function() {};
        Mautic.onPageLoad = function(container) {
            originalPageLoad.apply(this, arguments);
            routesLoaded = false;
            lastParams = null;
            currentSelectedValue = null;
            dropdownOpenedOnce = false;
            setTimeout(initRoutes, 800);
        };
    }

    // MutationObserver to catch dynamically added forms
    var observer = new MutationObserver(function (mutations) {
        var shouldInit = false;
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE && node.querySelector) {
                    var triggers = node.querySelectorAll('[data-bpmessage-routes-trigger]');
                    var selects = node.querySelectorAll('[data-bpmessage-routes-select]');
                    if (triggers.length > 0 || selects.length > 0) {
                        shouldInit = true;
                    }
                }
            });
        });

        if (shouldInit) {
            routesLoaded = false;
            lastParams = null;
            currentSelectedValue = null;
            dropdownOpenedOnce = false;
            setTimeout(initRoutes, 800);
        }
    });

    onReady(function() {
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });

    // Expose globally for manual triggering
    window.BpMessageRoutes = {
        init: initRoutes,
        load: loadRoutes
    };

})();
