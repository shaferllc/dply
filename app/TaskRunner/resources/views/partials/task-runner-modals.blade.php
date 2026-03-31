{{-- Shared alert + confirm modals for TaskRunner standalone pages (no window.alert / window.confirm). --}}
<div
    id="task-runner-alert-modal"
    class="hidden fixed inset-0 z-50 overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="tr-alert-title"
    aria-hidden="true"
>
    <div
        class="fixed inset-0 bg-black opacity-50 transition-opacity"
        data-task-runner-dismiss="alert"
    ></div>
    <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
        <div
            id="tr-alert-panel"
            class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-xl border-l-4 border-l-gray-300"
        >
            <div class="border-b border-gray-100 px-6 py-5 sm:px-7">
                <h2 id="tr-alert-title" class="text-lg font-semibold text-gray-900">Notice</h2>
            </div>
            <div class="px-6 py-5 sm:px-7">
                <p id="tr-alert-body" class="text-sm leading-relaxed text-gray-600 whitespace-pre-wrap"></p>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 sm:px-7">
                <button
                    type="button"
                    id="tr-alert-ok"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2"
                >
                    OK
                </button>
            </div>
        </div>
    </div>
</div>

<div
    id="task-runner-confirm-modal"
    class="hidden fixed inset-0 z-50 overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="tr-confirm-title"
    aria-hidden="true"
>
    <div
        class="fixed inset-0 bg-black opacity-50 transition-opacity"
        data-task-runner-dismiss="confirm-no"
    ></div>
    <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-xl">
            <div class="border-b border-gray-100 px-6 py-5 sm:px-7">
                <h2 id="tr-confirm-title" class="text-lg font-semibold text-gray-900">Confirm</h2>
            </div>
            <div class="px-6 py-5 sm:px-7">
                <p id="tr-confirm-body" class="text-sm leading-relaxed text-gray-600 whitespace-pre-wrap"></p>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-gray-100 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                <button
                    type="button"
                    id="tr-confirm-cancel"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    id="tr-confirm-ok"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2"
                >
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var confirmResolve = null;

    function $(id) {
        return document.getElementById(id);
    }

    function setAlertVariant(variant) {
        var panel = $('tr-alert-panel');
        if (!panel) return;
        panel.className =
            'relative w-full max-w-md rounded-2xl border border-zinc-200/90 bg-white shadow-xl border-l-4 ';
        var map = {
            success: 'border-l-green-500',
            error: 'border-l-red-500',
            info: 'border-l-blue-500',
            warning: 'border-l-yellow-500'
        };
        panel.className += map[variant] || map.info;
    }

    window.TaskRunnerModal = {
        showAlert: function (title, message, variant) {
            variant = variant || 'info';
            $('tr-alert-title').textContent = title || 'Notice';
            $('tr-alert-body').textContent = message || '';
            setAlertVariant(variant);
            var el = $('task-runner-alert-modal');
            el.classList.remove('hidden');
            el.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
            setTimeout(function () {
                $('tr-alert-ok').focus();
            }, 10);
        },

        closeAlert: function () {
            var el = $('task-runner-alert-modal');
            if (!el) return;
            el.classList.add('hidden');
            el.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
        },

        /** @returns {Promise<boolean>} */
        showConfirm: function (message, title) {
            title = title || 'Confirm';
            return new Promise(function (resolve) {
                confirmResolve = resolve;
                $('tr-confirm-title').textContent = title;
                $('tr-confirm-body').textContent = message || '';
                var el = $('task-runner-confirm-modal');
                el.classList.remove('hidden');
                el.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
                setTimeout(function () {
                    $('tr-confirm-cancel').focus();
                }, 10);
            });
        },

        confirmYes: function () {
            $('task-runner-confirm-modal').classList.add('hidden');
            $('task-runner-confirm-modal').setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
            if (confirmResolve) {
                confirmResolve(true);
                confirmResolve = null;
            }
        },

        confirmNo: function () {
            $('task-runner-confirm-modal').classList.add('hidden');
            $('task-runner-confirm-modal').setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
            if (confirmResolve) {
                confirmResolve(false);
                confirmResolve = null;
            }
        }
    };

    document.getElementById('tr-alert-ok').addEventListener('click', function () {
        TaskRunnerModal.closeAlert();
    });

    document.querySelectorAll('[data-task-runner-dismiss="alert"]').forEach(function (node) {
        node.addEventListener('click', function () {
            TaskRunnerModal.closeAlert();
        });
    });

    document.getElementById('tr-confirm-ok').addEventListener('click', function () {
        TaskRunnerModal.confirmYes();
    });

    document.getElementById('tr-confirm-cancel').addEventListener('click', function () {
        TaskRunnerModal.confirmNo();
    });

    document.querySelectorAll('[data-task-runner-dismiss="confirm-no"]').forEach(function (node) {
        node.addEventListener('click', function () {
            TaskRunnerModal.confirmNo();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        var alertEl = $('task-runner-alert-modal');
        var confirmEl = $('task-runner-confirm-modal');
        if (alertEl && !alertEl.classList.contains('hidden')) {
            TaskRunnerModal.closeAlert();
        } else if (confirmEl && !confirmEl.classList.contains('hidden')) {
            TaskRunnerModal.confirmNo();
        }
    });
})();
</script>
