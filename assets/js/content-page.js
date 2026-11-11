(function () {
    'use strict';

    const mode = document.getElementById('acm-topic-input-mode');
    const topic = document.getElementById('acm-topic');
    const topics = document.getElementById('acm-topics');
    const contentType = document.getElementById('acm-content-type');
    const category = document.getElementById('acm-category');
    const language = document.getElementById('acm-language');
    const execution = document.getElementById('acm-mode');

    if (mode && topic && topics && typeof acmContentForm !== 'undefined') {
        const syncTopics = function () {
            const automatic = execution && execution.value === 'automatic';
            const multiple = !automatic && mode.value === 'multiple';
            document.getElementById('acm-single-topic-row').hidden = automatic || multiple;
            document.getElementById('acm-multiple-topics-row').hidden = automatic || !multiple;
            topic.required = !automatic && !multiple;
            topics.required = !automatic && multiple;
            const campaign = document.getElementById('acm-campaign-fields');
            if (campaign) campaign.hidden = !automatic;
            if (campaign && campaign.querySelectorAll) campaign.querySelectorAll('input').forEach(input => { input.disabled = !automatic; });
            const keyword = document.getElementById('acm-primary-keyword');
            if (keyword) keyword.required = !!automatic;
            mode.disabled = !!automatic;
            const lines = multiple ? topics.value.split(/\r\n|[\n\r\u2028\u2029]/).map(line => line.trim()).filter(Boolean) : (topic.value.trim() ? [topic.value] : []);
            const normalized = lines.map(line => line.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/gu, ' ').toLowerCase());
            const duplicate = new Set(normalized).size !== normalized.length;
            const error = duplicate ? acmContentForm.strings.duplicate : lines.length > acmContentForm.limit ? acmContentForm.strings.tooMany : '';
            topics.setCustomValidity(multiple ? error : '');
            document.getElementById('acm-topic-count').textContent = acmContentForm.strings.count + lines.length + ' / ' + acmContentForm.limit + (error ? ' — ' + error : '');
            document.getElementById('acm-primary-keyword-label').textContent = multiple ? acmContentForm.strings.sharedKeyword : acmContentForm.strings.singleKeyword;
            document.getElementById('acm-secondary-keywords-label').textContent = multiple ? acmContentForm.strings.sharedSecondary : acmContentForm.strings.singleSecondary;
        };
        mode.addEventListener('change', syncTopics);
        if (execution) execution.addEventListener('change', syncTopics);
        topic.addEventListener('input', syncTopics);
        topics.addEventListener('input', syncTopics);
        syncTopics();
    }

    if (contentType && category && language && typeof acmContentForm !== 'undefined') {
        const syncCategories = function () {
            Array.from(category.options).forEach(function (option) {
                const matches = !option.value || (!option.dataset.invalid && option.dataset.contentType === contentType.value && (option.dataset.languages || '').split(' ').includes(language.value));
                option.hidden = !matches && !option.selected;
                option.disabled = !matches && !option.selected;
            });
            const selected = category.options[category.selectedIndex];
            const valid = !selected || !selected.value || (!selected.dataset.invalid && selected.dataset.contentType === contentType.value && (selected.dataset.languages || '').split(' ').includes(language.value));
            category.setCustomValidity(valid ? '' : acmContentForm.strings.categoryMismatch);
        };
        contentType.addEventListener('change', syncCategories);
        language.addEventListener('change', syncCategories);
        category.addEventListener('change', syncCategories);
        syncCategories();
    }
}());
