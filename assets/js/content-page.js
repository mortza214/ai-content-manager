(function () {
    'use strict';

    const mode = document.getElementById('acm-topic-input-mode');
    const topic = document.getElementById('acm-topic');
    const topics = document.getElementById('acm-topics');
    const contentType = document.getElementById('acm-content-type');
    const category = document.getElementById('acm-category');

    if (mode && topic && topics && typeof acmContentForm !== 'undefined') {
        const syncTopics = function () {
            const multiple = mode.value === 'multiple';
            document.getElementById('acm-single-topic-row').hidden = multiple;
            document.getElementById('acm-multiple-topics-row').hidden = !multiple;
            topic.required = !multiple;
            topics.required = multiple;
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
        topic.addEventListener('input', syncTopics);
        topics.addEventListener('input', syncTopics);
        syncTopics();
    }

    if (contentType && category) {
        const syncCategories = function () {
            Array.from(category.options).forEach(function (option) {
                if (!option.dataset.contentType) {
                    return;
                }
                option.hidden = option.dataset.contentType !== contentType.value;
            });
            const selected = category.options[category.selectedIndex];
            if (selected && selected.dataset.contentType && selected.dataset.contentType !== contentType.value) {
                category.value = '';
            }
        };
        contentType.addEventListener('change', syncCategories);
        syncCategories();
    }
}());
