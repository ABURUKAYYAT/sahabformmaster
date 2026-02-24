(function () {
    'use strict';

    function getQueue(queueKey) {
        try {
            const raw = localStorage.getItem(queueKey);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function setQueue(queueKey, queue) {
        localStorage.setItem(queueKey, JSON.stringify(queue));
    }

    function serializeForm(form) {
        const formData = new FormData(form);
        const pairs = [];
        formData.forEach((value, key) => {
            pairs.push([key, String(value)]);
        });
        return pairs;
    }

    function enqueueFormSubmission(form, options) {
        const queue = getQueue(options.queueKey);
        const action = form.getAttribute('action') || window.location.href;
        const url = new URL(action, window.location.href).toString();

        queue.push({
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            url: url,
            payload: serializeForm(form),
            created_at: new Date().toISOString()
        });

        setQueue(options.queueKey, queue);
        return queue.length;
    }

    function updateStatus(statusElement, queueKey, prefixText) {
        if (!statusElement) return;
        const queueCount = getQueue(queueKey).length;
        const online = navigator.onLine;
        let text = online ? 'Online' : 'Offline';
        if (queueCount > 0) {
            text += ' | Pending sync: ' + queueCount;
        }

        statusElement.style.display = 'block';
        statusElement.textContent = (prefixText ? prefixText + ' ' : '') + text;
        statusElement.className = online ? 'alert alert-info' : 'alert alert-warning';
        statusElement.style.marginBottom = '1rem';
    }

    async function flushQueue(queueKey, statusElement, prefixText) {
        if (!navigator.onLine) {
            updateStatus(statusElement, queueKey, prefixText);
            return;
        }

        const queue = getQueue(queueKey);
        if (!queue.length) {
            updateStatus(statusElement, queueKey, prefixText);
            return;
        }

        const remaining = [];
        for (const item of queue) {
            try {
                const params = new URLSearchParams();
                for (const pair of item.payload) {
                    params.append(pair[0], pair[1]);
                }

                const response = await fetch(item.url, {
                    method: item.method || 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: params.toString()
                });

                if (!response.ok) {
                    remaining.push(item);
                }
            } catch (e) {
                remaining.push(item);
                break;
            }
        }

        setQueue(queueKey, remaining);
        updateStatus(statusElement, queueKey, prefixText);
    }

    function registerServiceWorker(swPath) {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        navigator.serviceWorker.register(swPath).catch(function () {
            // silent fail for unsupported/insecure contexts
        });
    }

    function attachFormInterception(options, statusElement) {
        const forms = document.querySelectorAll(options.formSelector);
        forms.forEach((form) => {
            form.addEventListener('submit', function (event) {
                const method = (form.getAttribute('method') || 'GET').toUpperCase();
                if (method !== 'POST') return;

                if (navigator.onLine) {
                    return;
                }

                event.preventDefault();
                const count = enqueueFormSubmission(form, options);
                updateStatus(statusElement, options.queueKey, options.statusPrefix);

                const offlineMessage = form.getAttribute('data-offline-message')
                    || 'You are offline. This action was queued and will sync automatically once online.';
                window.alert(offlineMessage + ' Pending queue: ' + count + '.');
            });
        });
    }

    function init(userOptions) {
        const options = Object.assign({
            queueKey: 'cbt_offline_sync_queue_v1',
            formSelector: 'form[data-offline-sync="true"]',
            statusElementId: 'cbt-offline-status',
            statusPrefix: 'CBT Sync:',
            swPath: '../cbt-sw.js',
            autoFlushIntervalMs: 12000
        }, userOptions || {});

        const statusElement = document.getElementById(options.statusElementId);
        registerServiceWorker(options.swPath);
        attachFormInterception(options, statusElement);
        updateStatus(statusElement, options.queueKey, options.statusPrefix);

        window.addEventListener('online', function () {
            flushQueue(options.queueKey, statusElement, options.statusPrefix);
        });
        window.addEventListener('offline', function () {
            updateStatus(statusElement, options.queueKey, options.statusPrefix);
        });

        flushQueue(options.queueKey, statusElement, options.statusPrefix);
        window.setInterval(function () {
            flushQueue(options.queueKey, statusElement, options.statusPrefix);
        }, options.autoFlushIntervalMs);
    }

    window.CBTOfflineSync = {
        init: init,
        flushQueue: flushQueue
    };
})();
