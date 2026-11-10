(function () {
    'use strict';

    const button = document.getElementById('acm-test-openai-connection');
    const result = document.getElementById('acm-openai-test-result');
    const model = document.getElementById('acm-openai-model');

    if (!button || !result || !model || typeof acmOpenAiTest === 'undefined') {
        return;
    }

    button.addEventListener('click', async function () {
        button.disabled = true;
        result.textContent = acmOpenAiTest.strings.testing;
        result.className = '';

        const body = new URLSearchParams({
            action: 'acm_test_openai_connection',
            nonce: acmOpenAiTest.nonce,
            model: model.value,
        });

        try {
            const response = await fetch(acmOpenAiTest.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
            });
            const payload = await response.json();
            const succeeded = response.ok && payload.success;

            result.textContent = payload.data && payload.data.message
                ? payload.data.message
                : (succeeded ? acmOpenAiTest.strings.success : acmOpenAiTest.strings.failure);
            result.className = succeeded ? 'notice-success' : 'notice-error';
        } catch (error) {
            result.textContent = acmOpenAiTest.strings.failure;
            result.className = 'notice-error';
        } finally {
            button.disabled = false;
        }
    });
}());