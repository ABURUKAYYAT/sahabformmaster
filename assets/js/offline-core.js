(() => {
    const DB_NAME = 'sahab_offline';
    const STORE_NAME = 'outbox';
    const STATUS_ID = 'offline-status-banner';
    const STATUS_TEXT_ID = 'offline-status-text';
    const STATUS_COUNT_ID = 'offline-status-count';
    const STATUS_RETRY_ID = 'offline-status-retry';
    const STATUS_SYNCING_CLASS = 'offline-banner-syncing';
    const STATUS_OFFLINE_CLASS = 'offline-banner-offline';
    const STATUS_ONLINE_CLASS = 'offline-banner-online';

    let syncing = false;

    function openDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function withStore(mode, callback) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, mode);
            const store = tx.objectStore(STORE_NAME);
            const result = callback(store);
            tx.oncomplete = () => resolve(result);
            tx.onerror = () => reject(tx.error);
        });
    }

    function getAllOutbox() {
        return withStore('readonly', (store) => {
            return new Promise((resolve, reject) => {
                const req = store.getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        });
    }

    function addOutbox(item) {
        return withStore('readwrite', (store) => store.put(item));
    }

    function removeOutbox(id) {
        return withStore('readwrite', (store) => store.delete(id));
    }

    function findBannerAnchor() {
        const selectors = [
            '[data-offline-banner-anchor]',
            '.hero',
            '.hero-section',
            '.hero-card',
            '.attendance-hero',
            '.profile-hero',
            '.news-hero',
            '.timebook-hero',
            '.form-header',
            '.content-header',
            'body > header',
            '.dashboard-header',
            '.mobile-nav-header',
            'main',
            '.main-content'
        ];

        for (const selector of selectors) {
            const element = document.querySelector(selector);
            if (element) return element;
        }

        return null;
    }

    function ensureBanner() {
        if (document.getElementById(STATUS_ID)) return;

        const banner = document.createElement('div');
        banner.id = STATUS_ID;
        banner.className = 'offline-banner offline-banner-online';

        const text = document.createElement('div');
        text.id = STATUS_TEXT_ID;
        text.textContent = 'Online';

        const right = document.createElement('div');
        right.className = 'offline-banner-right';

        const count = document.createElement('span');
        count.id = STATUS_COUNT_ID;
        count.className = 'offline-banner-count';
        count.textContent = '';

        const retry = document.createElement('button');
        retry.id = STATUS_RETRY_ID;
        retry.type = 'button';
        retry.className = 'offline-banner-retry';
        retry.textContent = 'Sync now';
        retry.addEventListener('click', () => syncOutbox());

        right.appendChild(count);
        right.appendChild(retry);

        banner.appendChild(text);
        banner.appendChild(right);

        const anchor = findBannerAnchor();
        if (anchor && anchor.parentNode) {
            anchor.insertAdjacentElement('afterend', banner);
            return;
        }

        const firstElement = document.body.firstElementChild;
        if (firstElement) {
            firstElement.insertAdjacentElement('afterend', banner);
        } else {
            document.body.appendChild(banner);
        }
    }

    function updateBanner(state, message, pendingCount = 0) {
        ensureBanner();
        const banner = document.getElementById(STATUS_ID);
        const text = document.getElementById(STATUS_TEXT_ID);
        const count = document.getElementById(STATUS_COUNT_ID);

        banner.classList.remove(STATUS_SYNCING_CLASS, STATUS_OFFLINE_CLASS, STATUS_ONLINE_CLASS);

        if (state === 'syncing') {
            banner.classList.add(STATUS_SYNCING_CLASS);
        } else if (state === 'offline') {
            banner.classList.add(STATUS_OFFLINE_CLASS);
        } else {
            banner.classList.add(STATUS_ONLINE_CLASS);
        }

        text.textContent = message;
        count.textContent = pendingCount > 0 ? `${pendingCount} pending` : '';
    }

    function hasFileInputs(form) {
        return form.querySelector('input[type="file"]') !== null;
    }

    function hasSelectedFiles(form) {
        const inputs = form.querySelectorAll('input[type="file"]');
        for (const input of inputs) {
            if (input.files && input.files.length > 0) return true;
        }
        return false;
    }

    function serializeForm(form) {
        const params = new URLSearchParams();
        const data = new FormData(form);
        for (const [key, value] of data.entries()) {
            if (value instanceof File) continue;
            params.append(key, value);
        }
        return params.toString();
    }

    function getMaxBytes(form) {
        const attr = form.getAttribute('data-offline-max-bytes');
        if (!attr) return null;
        const parsed = parseInt(attr, 10);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function getFilesPayload(form) {
        const data = new FormData(form);
        const fields = [];
        const files = [];
        let totalBytes = 0;

        for (const [key, value] of data.entries()) {
            if (value instanceof File) {
                if (value && value.size > 0) {
                    files.push({ key, file: value });
                    totalBytes += value.size;
                }
            } else {
                fields.push([key, value]);
            }
        }

        return { fields, files, totalBytes };
    }

    async function serializeFiles(files) {
        const serialized = [];
        for (const { key, file } of files) {
            const buffer = await file.arrayBuffer();
            serialized.push({
                key,
                name: file.name,
                type: file.type,
                data: buffer
            });
        }
        return serialized;
    }

    function resolveFormAction(form) {
        if (form.action) return form.action;
        return window.location.href;
    }

    function makeId() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return `offline_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
    }

    async function queueForm(form) {
        const id = makeId();
        const allowFiles = form.getAttribute('data-offline-allow-files') === '1';
        let item;

        if (allowFiles && hasFileInputs(form)) {
            const payload = getFilesPayload(form);
            const maxBytes = getMaxBytes(form);
            if (maxBytes !== null && payload.totalBytes > maxBytes) {
                updateBanner('offline', 'Offline: total upload size exceeds limit. Connect to submit files.', 0);
                return;
            }

            const serializedFiles = await serializeFiles(payload.files);
            payload.fields.push(['__offline_request_id', id]);

            item = {
                id,
                url: resolveFormAction(form),
                method: (form.method || 'POST').toUpperCase(),
                bodyType: 'formdata',
                fields: payload.fields,
                files: serializedFiles,
                createdAt: Date.now()
            };
        } else {
            const payload = serializeForm(form);
            const body = payload ? `${payload}&__offline_request_id=${encodeURIComponent(id)}` : `__offline_request_id=${encodeURIComponent(id)}`;
            item = {
                id,
                url: resolveFormAction(form),
                method: (form.method || 'POST').toUpperCase(),
                bodyType: 'urlencoded',
                body,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                createdAt: Date.now()
            };
        }

        await addOutbox(item);
        const pending = await getAllOutbox();
        updateBanner('offline', 'Offline: saved locally. Will sync when online.', pending.length);
    }

    async function syncOutbox() {
        if (syncing || !navigator.onLine) return;
        syncing = true;
        const items = await getAllOutbox();
        updateBanner('syncing', 'Syncing offline data...', items.length);

        for (const item of items) {
            try {
                let response;
                if (item.bodyType === 'formdata') {
                    const formData = new FormData();
                    (item.fields || []).forEach(([key, value]) => {
                        formData.append(key, value);
                    });
                    (item.files || []).forEach((fileItem) => {
                        const blob = new Blob([fileItem.data], { type: fileItem.type || 'application/octet-stream' });
                        formData.append(fileItem.key, blob, fileItem.name || 'upload');
                    });
                    response = await fetch(item.url, {
                        method: item.method,
                        body: formData,
                        credentials: 'same-origin'
                    });
                } else {
                    response = await fetch(item.url, {
                        method: item.method,
                        headers: item.headers,
                        body: item.body,
                        credentials: 'same-origin'
                    });
                }
                if (!response.ok) {
                    break;
                }
                await removeOutbox(item.id);
            } catch (err) {
                break;
            }
        }

        const remaining = await getAllOutbox();
        if (remaining.length > 0) {
            updateBanner(navigator.onLine ? 'online' : 'offline', navigator.onLine ? 'Online: some items pending.' : 'Offline: changes queued.', remaining.length);
        } else {
            updateBanner(navigator.onLine ? 'online' : 'offline', navigator.onLine ? 'Online' : 'Offline', 0);
        }
        syncing = false;
    }

    function initNetworkListeners() {
        window.addEventListener('online', () => {
            syncOutbox();
        });
        window.addEventListener('offline', async () => {
            const pending = await getAllOutbox();
            updateBanner('offline', 'Offline: changes will be saved locally.', pending.length);
        });
    }

    function initFormInterceptors() {
        document.querySelectorAll('form[data-offline-sync="1"]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                if (navigator.onLine) return;
                event.preventDefault();

                const allowFiles = form.getAttribute('data-offline-allow-files') === '1';
                if (!allowFiles && hasFileInputs(form) && hasSelectedFiles(form)) {
                    updateBanner('offline', 'Offline: file uploads are not supported. Connect to submit files.', 0);
                    return;
                }

                await queueForm(form);
            });
        });
    }

    function registerServiceWorker() {
        const meta = document.querySelector('meta[name="pwa-sw"]');
        const swUrl = meta ? meta.content : '';
        if (!swUrl || !('serviceWorker' in navigator)) return;

        window.addEventListener('load', () => {
            navigator.serviceWorker.register(swUrl).catch(() => {});
        });
    }

    async function initStatus() {
        const pending = await getAllOutbox();
        if (navigator.onLine) {
            updateBanner('online', pending.length ? 'Online: pending sync.' : 'Online', pending.length);
            if (pending.length) {
                syncOutbox();
            }
        } else {
            updateBanner('offline', 'Offline: changes will be saved locally.', pending.length);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        registerServiceWorker();
        initNetworkListeners();
        initFormInterceptors();
        initStatus();
    });
})();
