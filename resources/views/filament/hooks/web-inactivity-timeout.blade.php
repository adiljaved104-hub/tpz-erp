@auth
    @php
        $inactivity = app(\App\Services\Security\WebInactivityService::class);
        $lastActivityAt = (int) session(\App\Services\Security\WebInactivityService::SESSION_KEY, now()->timestamp);
    @endphp

    <div
        id="tpz-inactivity-warning"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-950/50 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tpz-inactivity-warning-title"
        x-persist="tpz-inactivity-warning"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 id="tpz-inactivity-warning-title" class="text-lg font-semibold text-gray-950 dark:text-white">
                Session expiring soon
            </h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                Your session will expire due to inactivity in <span id="tpz-inactivity-minutes">5 minutes</span>.
            </p>
            <div class="mt-5 flex justify-end">
                <button
                    id="tpz-stay-signed-in"
                    type="button"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2"
                >
                    Stay Signed In
                </button>
            </div>
        </div>
    </div>

    <script data-navigate-once>
        (() => {
            if (window.__tpzWebInactivityTimeout) {
                return;
            }

            window.__tpzWebInactivityTimeout = true;

            const timeoutSeconds = @json($inactivity->timeoutSeconds());
            const warningSeconds = Math.min(300, Math.max(1, timeoutSeconds - 1));
            const activityUrl = @json(route('auth.session.activity.store'));
            const statusUrl = @json(route('auth.session.activity.show'));
            const loginUrl = @json(route('filament.admin.auth.login'));
            const csrfToken = @json(csrf_token());
            const warning = document.getElementById('tpz-inactivity-warning');
            const remainingLabel = document.getElementById('tpz-inactivity-minutes');
            const staySignedIn = document.getElementById('tpz-stay-signed-in');
            let expiresAt = @json($lastActivityAt + $inactivity->timeoutSeconds()) * 1000;
            let lastServerRefreshAt = 0;
            let refreshPromise = null;
            let statusPromise = null;

            const redirectToLogin = (payload = null) => {
                window.location.assign(payload?.redirect || loginUrl);
            };

            const handleResponse = async (response) => {
                if (response.status === 401 || response.status === 419) {
                    let payload = null;

                    try {
                        payload = await response.json();
                    } catch (_) {
                        // A redirected or expired session may return HTML.
                    }

                    redirectToLogin(payload);
                    throw new Error('session-expired');
                }

                if (! response.ok) {
                    throw new Error('session-request-failed');
                }

                const payload = await response.json();
                expiresAt = Number(payload.expires_at) * 1000;

                return payload;
            };

            const refreshServerActivity = (force = false) => {
                const now = Date.now();

                if (refreshPromise || (! force && now - lastServerRefreshAt < 30000)) {
                    return refreshPromise;
                }

                lastServerRefreshAt = now;
                refreshPromise = fetch(activityUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-TPZ-User-Activity': '1',
                    },
                })
                    .then(handleResponse)
                    .catch((error) => {
                        if (error.message !== 'session-expired') {
                            lastServerRefreshAt = 0;
                        }
                    })
                    .finally(() => refreshPromise = null);

                return refreshPromise;
            };

            const checkServerStatus = () => {
                if (statusPromise) {
                    return statusPromise;
                }

                statusPromise = fetch(statusUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                })
                    .then(handleResponse)
                    .catch((error) => {
                        if (error.message !== 'session-expired') {
                            redirectToLogin();
                        }
                    })
                    .finally(() => statusPromise = null);

                return statusPromise;
            };

            const hideWarning = () => {
                warning.classList.add('hidden');
                warning.classList.remove('flex');
            };

            const showWarning = (remainingSeconds) => {
                const minutes = Math.max(1, Math.ceil(remainingSeconds / 60));
                remainingLabel.textContent = `${minutes} minute${minutes === 1 ? '' : 's'}`;
                warning.classList.remove('hidden');
                warning.classList.add('flex');
            };

            const recordGenuineActivity = () => {
                if (document.visibilityState !== 'visible') {
                    return;
                }

                expiresAt = Date.now() + (timeoutSeconds * 1000);
                hideWarning();
                refreshServerActivity();
            };

            ['pointerdown', 'keydown', 'input', 'touchstart'].forEach((eventName) => {
                document.addEventListener(eventName, recordGenuineActivity, { capture: true, passive: true });
            });

            staySignedIn.addEventListener('click', () => refreshServerActivity(true));

            window.setInterval(() => {
                const remainingSeconds = Math.ceil((expiresAt - Date.now()) / 1000);

                if (remainingSeconds <= 0) {
                    checkServerStatus();

                    return;
                }

                if (remainingSeconds <= warningSeconds) {
                    showWarning(remainingSeconds);
                } else {
                    hideWarning();
                }
            }, 15000);
        })();
    </script>
@endauth
