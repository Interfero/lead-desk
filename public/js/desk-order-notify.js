/**
 * Звук и toast о новых заявках в Едином окне (CRM + КП).
 */
(function () {
    const POLL_MS = 3000;
    const STORAGE_SNAPSHOT = 'desk_open_order_ids_v3';
    const STORAGE_NOTIFIED = 'desk_notified_order_ids_v3';

    document.addEventListener('DOMContentLoaded', function () {
        const body = document.body;
        if (!body || body.dataset.deskNotifyPoll !== '1') {
            return;
        }
        if (body.dataset.deskNotifyBound === '1') {
            return;
        }
        body.dataset.deskNotifyBound = '1';

        const url = (body.dataset.deskNotifyUrl || '/desk/notifications/new-orders').trim();
        const soundUrl = (body.dataset.deskNotifySound || '/sounds/order-notify.mp3').trim();
        const ordersUrl = (body.dataset.deskOrdersUrl || '/desk').trim();

        const testBtn = document.getElementById('deskNotifyTestBtn');
        const notifyBtn = document.getElementById('deskNotifyEnableBtn');
        const statusEl = document.getElementById('deskNotifyStatus');
        const toastHost = document.getElementById('deskNotifyToastHost');

        if (window.LcNotifySound) {
            window.LcNotifySound.bindUnlock(soundUrl);
        }

        function setStatus(text) {
            if (statusEl) {
                statusEl.textContent = text || '';
            }
        }

        function playSound() {
            if (!window.LcNotifySound) {
                setStatus('Нет модуля звука');
                return Promise.resolve(false);
            }
            return window.LcNotifySound.play(soundUrl).then(function (mode) {
                setStatus(mode ? ('Звук: ' + mode) : 'Кликните страницу');
                return !!mode;
            });
        }

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                window.LcNotifySound?.unlock(soundUrl).then(function () {
                    playSound();
                    showToast('Тест ЕО', 'Если слышите — уведомления работают');
                });
            });
        }
        if (notifyBtn && 'Notification' in window) {
            notifyBtn.addEventListener('click', function () {
                window.LcNotifySound?.unlock(soundUrl);
                Notification.requestPermission();
            });
        }

        function showToast(title, message) {
            if (!toastHost) {
                return;
            }
            const el = document.createElement('div');
            el.className = 'desk-notify-toast';
            el.innerHTML =
                '<strong class="desk-notify-toast__title"></strong>' +
                '<div class="desk-notify-toast__body"></div>';
            el.querySelector('.desk-notify-toast__title').textContent = title;
            el.querySelector('.desk-notify-toast__body').textContent = message;
            el.addEventListener('click', function () {
                window.location.href = ordersUrl;
            });
            toastHost.appendChild(el);
            setTimeout(function () {
                el.classList.add('desk-notify-toast--fade');
                setTimeout(function () { el.remove(); }, 400);
            }, 12000);
        }

        function readIds(key) {
            try {
                const raw = localStorage.getItem(key);
                const parsed = raw ? JSON.parse(raw) : [];
                return Array.isArray(parsed) ? parsed.map(Number).filter(function (n) { return n > 0; }) : [];
            } catch (e) {
                return [];
            }
        }

        function writeIds(key, ids) {
            try {
                const unique = Array.from(new Set(ids.map(Number).filter(function (n) { return n > 0; }))).slice(-4000);
                localStorage.setItem(key, JSON.stringify(unique));
            } catch (e) {}
        }

        function notifyNewOrders(items) {
            if (!items.length) {
                return;
            }
            playSound();
            items.sort(function (a, b) { return Number(b.id) - Number(a.id); }).slice(0, 5).forEach(function (o) {
                const crm = o.crm_label || (o.crm_type === 'crm2_http' ? 'КП' : 'CRM');
                const city = o.city_name ? (' · ' + o.city_name) : '';
                showToast('Новая заявка', crm + ' №' + (o.external_id || o.id) + city);
            });
            if (navigator.vibrate) {
                navigator.vibrate(180);
            }
        }

        let initialized = false;
        let lastMaxId = 0;

        async function poll() {
            try {
                const res = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!res.ok) {
                    setStatus('HTTP ' + res.status);
                    return;
                }
                const data = await res.json();
                const orders = Array.isArray(data.orders) ? data.orders : [];
                const ids = orders.map(function (o) { return Number(o.id); }).filter(function (n) { return n > 0; });
                const maxId = ids.length ? Math.max.apply(null, ids) : 0;

                if (!initialized) {
                    writeIds(STORAGE_SNAPSHOT, ids);
                    lastMaxId = maxId;
                    initialized = true;
                    setStatus('Слежу (' + orders.length + ')');
                    return;
                }

                const prev = new Set(readIds(STORAGE_SNAPSHOT));
                const already = new Set(readIds(STORAGE_NOTIFIED));
                let brandNew = orders.filter(function (o) {
                    const id = Number(o.id);
                    return id > 0 && !prev.has(id) && !already.has(id);
                });
                if (brandNew.length === 0 && maxId > lastMaxId) {
                    brandNew = orders.filter(function (o) {
                        const id = Number(o.id);
                        return id > lastMaxId && !already.has(id);
                    });
                }

                if (brandNew.length > 0) {
                    notifyNewOrders(brandNew);
                    writeIds(STORAGE_NOTIFIED, Array.from(already).concat(brandNew.map(function (o) { return Number(o.id); })));
                    setStatus('Новых: ' + brandNew.length);
                }

                writeIds(STORAGE_SNAPSHOT, ids);
                lastMaxId = Math.max(lastMaxId, maxId);
            } catch (e) {
                setStatus('Ошибка опроса');
                console.warn('[desk-notify]', e);
            }
        }

        poll();
        setInterval(poll, POLL_MS);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                poll();
            }
        });
    });
})();
