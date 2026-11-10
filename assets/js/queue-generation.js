(function () {
    'use strict';
    if (typeof acmQueueGeneration === 'undefined') { return; }
    document.querySelectorAll('.acm-generate').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            const message = button.parentElement.querySelector('.acm-generation-message');
            message.textContent = acmQueueGeneration.strings.generating;
            const data = new URLSearchParams({action: 'acm_generate_item', item_id: button.dataset.itemId, nonce: acmQueueGeneration.nonce});
            try {
                const response = await fetch(acmQueueGeneration.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: data});
                const result = await response.json();
                message.textContent = result.data && result.data.message ? result.data.message : acmQueueGeneration.strings.failure;
                if (result.success) { window.location.reload(); }
            } catch (error) {
                message.textContent = acmQueueGeneration.strings.connectionError;
            }
        });
    });
}());
