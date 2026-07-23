(function() {
    var fitState = null;

    function navigateFits(target) {
        if (typeof navigateTo === 'function') navigateTo(target);
        else window.location.href = target;
    }

    function initFitShipSearch() {
        if (!window.jQuery || !jQuery.fn.zz_search || !fitState) return;

        var input = jQuery('#fit-ship-search');
        if (!input.length || input.data('zz_search')) return;
        fitState.fitSearchInput = input;

        input.zz_search(function(data, event) {
            if (event) event.preventDefault();
            if (!data || data.type !== 'ship') return;
            navigateFits('/fits/' + encodeURIComponent(data.id) + '/');
        });
    }

    function fitShipFormSubmit(event) {
        event.preventDefault();

        var currentState = fitState;
        var input = document.getElementById('fit-ship-search');
        var search = input ? input.value.trim() : '';
        if (search == '') {
            navigateFits('/fits/');
            return;
        }
        if (/^\d+$/.test(search)) {
            navigateFits('/fits/' + encodeURIComponent(search) + '/');
            return;
        }

        fetch('/autocomplete/ship/' + encodeURIComponent(search) + '/', {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function(response) {
                if (!response.ok) throw new Error('Unable to search ships.');
                return response.json();
            })
            .then(function(result) {
                if (fitState !== currentState) return;
                var ship = Array.isArray(result) && result.length > 0 ? result[0] : null;
                navigateFits(ship && ship.id ? '/fits/' + encodeURIComponent(ship.id) + '/' : '/fits/' + encodeURIComponent(search) + '/');
            })
            .catch(function() {
                if (fitState === currentState) navigateFits('/fits/' + encodeURIComponent(search) + '/');
            });
    }

    function loadFitKillerDetail(detail) {
        var hash = detail.getAttribute('data-zkb-fit-detail-hash');
        var body = detail.querySelector('[data-zkb-fit-detail-body]');
        if (!hash || !body || body.getAttribute('data-zkb-loaded') === 'true' || body.getAttribute('data-zkb-loading') === 'true') return;

        body.setAttribute('data-zkb-loading', 'true');
        body.innerHTML = '<div class="text-muted" role="status">Loading fit...</div>';
        fetch('/fits/detail/' + encodeURIComponent(hash) + '/')
            .then(function(response) {
                if (!response.ok) throw new Error('Unable to load fit.');
                return response.text();
            })
            .then(function(html) {
                body.innerHTML = html;
                body.setAttribute('data-zkb-loaded', 'true');
                body.removeAttribute('data-zkb-loading');
            })
            .catch(function() {
                body.innerHTML = '<div class="alert alert-warning mb-0" role="alert">Unable to load this fit.</div>';
                body.removeAttribute('data-zkb-loading');
            });
    }

    function announceFitStatus(message) {
        var status = document.getElementById('fit-action-status');
        if (status) status.textContent = message;
    }

    function setFitButtonLabel(button, open) {
        var label = button.querySelector('.fit-button-label');
        if (label) label.textContent = open ? 'Hide Fit' : 'View Fit';
        var accessibleLabel = button.getAttribute(open ? 'data-zkb-hide-label' : 'data-zkb-view-label');
        if (accessibleLabel) button.setAttribute('aria-label', accessibleLabel);
        var icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-eye', !open);
            icon.classList.toggle('fa-eye-slash', open);
        }
        button.classList.toggle('btn-primary', open);
        button.classList.toggle('btn-secondary', !open);
    }

    function showFitButton(button) {
        var detail = document.getElementById('fit-detail-' + button.getAttribute('data-zkb-fit-detail'));
        var row = detail ? detail.closest('.fit-killer-detail-row') : null;
        if (!detail || !row) return;

        document.querySelectorAll('.fit-killer-detail-row').forEach(function(openRow) {
            if (openRow !== row) {
                openRow.classList.add('d-none');
                openRow.setAttribute('aria-hidden', 'true');
            }
        });
        document.querySelectorAll('[data-zkb-fit-detail][aria-expanded="true"]').forEach(function(openButton) {
            if (openButton !== button) {
                openButton.setAttribute('aria-expanded', 'false');
                setFitButtonLabel(openButton, false);
            }
        });
        row.classList.remove('d-none');
        row.setAttribute('aria-hidden', 'false');
        button.setAttribute('aria-expanded', 'true');
        setFitButtonLabel(button, true);

        var shipTypeID = detail.getAttribute('data-zkb-fit-ship');
        var details = shipTypeID ? document.querySelectorAll('.fit-killer-detail[data-zkb-fit-ship="' + shipTypeID + '"]') : [detail];
        details.forEach(loadFitKillerDetail);
    }

    function hideFitButton(button) {
        var detail = document.getElementById('fit-detail-' + button.getAttribute('data-zkb-fit-detail'));
        var row = detail ? detail.closest('.fit-killer-detail-row') : null;
        if (!row) return;

        row.classList.add('d-none');
        row.setAttribute('aria-hidden', 'true');
        button.setAttribute('aria-expanded', 'false');
        setFitButtonLabel(button, false);
    }

    function fitDetailClick(event) {
        var button = event.target.closest('[data-zkb-fit-detail]');
        if (!button) return;

        if (button.getAttribute('aria-expanded') === 'true') hideFitButton(button);
        else showFitButton(button);
    }

    function copyEftClick(event) {
        var button = event.target.closest('[data-zkb-copy-eft]');
        if (!button) return;

        var box = button.closest('.position-relative');
        var eft = box ? box.querySelector('textarea') : null;
        if (!eft || !navigator.clipboard || !navigator.clipboard.writeText) {
            announceFitStatus('Clipboard is not available');
            return;
        }

        navigator.clipboard.writeText(eft.value).then(function() {
            announceFitStatus('EFT fit copied to clipboard');
            if (typeof showToast === 'function') showToast('EFT fit copied to your clipboard');
        }).catch(function() {
            announceFitStatus('Unable to copy EFT fit');
        });
    }

    function openTopFit() {
        var firstFitButton = document.querySelector('[data-zkb-fit-detail]');
        if (firstFitButton) showFitButton(firstFitButton);
    }

    function cleanupFits() {
        var state = fitState;
        if (!state) return;

        if (state.openTopFitTimer != null) clearTimeout(state.openTopFitTimer);
        if (state.fitShipForm) state.fitShipForm.removeEventListener('submit', fitShipFormSubmit);
        document.removeEventListener('DOMContentLoaded', zkbInitFits);
        document.removeEventListener('click', fitDetailClick);
        document.removeEventListener('click', copyEftClick);
        if (state.fitSearchInput) {
            var autocomplete = state.fitSearchInput.data('zz_search');
            if (autocomplete && autocomplete.data) {
                if (autocomplete.data.throttle) clearTimeout(autocomplete.data.throttle);
                if (autocomplete.data.menu) autocomplete.data.menu.remove();
            }
            state.fitSearchInput.removeData('zz_search');
        }
        fitState = null;
    }

    function zkbInitFits() {
        var fitShipForm = document.getElementById('fit-ship-form');
        if (!fitShipForm) return;
        if (fitState && fitState.fitShipForm === fitShipForm) return;

        cleanupFits();
        fitState = {
            fitShipForm: fitShipForm,
            fitSearchInput: null,
            openTopFitTimer: null
        };

        initFitShipSearch();
        fitShipForm.addEventListener('submit', fitShipFormSubmit);
        document.addEventListener('click', fitDetailClick);
        document.addEventListener('click', copyEftClick);
        if (fitShipForm.getAttribute('data-zkb-open-top-fit') === 'true') fitState.openTopFitTimer = setTimeout(openTopFit, 0);

        window.zkbPageCleanup = cleanupFits;
    }

    window.zkbInitFits = zkbInitFits;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', zkbInitFits);
    else zkbInitFits();
})();
