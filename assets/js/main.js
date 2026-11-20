/**
 * RxCore — Main Client Script (v2.0)
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Mobile Sidebar Toggle
    const sidebarToggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.app-sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebarToggleBtn && sidebar) {
        sidebarToggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('show');
        });
    }

    if (overlay && sidebar) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // 2. Theme Toggle (Aurora Pastel <-> Aurora Dark)
    const themeBtn = document.getElementById('rxThemeToggle');
    const themeIcon = document.getElementById('rxThemeIcon');
    const themeLabel = document.getElementById('rxThemeLabel');

    function syncThemeUi(theme) {
        if (!themeIcon || !themeLabel) return;
        if (theme === 'aurora-dark') {
            themeIcon.className = 'bi bi-moon-stars';
            themeLabel.textContent = 'โหมดมืด Aurora';
        } else {
            themeIcon.className = 'bi bi-sun';
            themeLabel.textContent = 'โหมดพาสเทล';
        }
    }

    const currentTheme = document.documentElement.getAttribute('data-rx-theme') || 'aurora';
    syncThemeUi(currentTheme);

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            const now = document.documentElement.getAttribute('data-rx-theme') === 'aurora-dark' ? 'aurora' : 'aurora-dark';
            document.documentElement.setAttribute('data-rx-theme', now);
            try { localStorage.setItem('rxcore_ui_theme', now); } catch (e) {}
            syncThemeUi(now);
            window.dispatchEvent(new CustomEvent('rxcore:themechange', { detail: { theme: now } }));
        });
    }

    // 3. Clean Toast Notification Helper
    window.rxToast = function (title, message, type = 'info') {
        let container = document.getElementById('rxToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'rxToastContainer';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }

        const iconMap = {
            success: 'bi-check-circle-fill text-success',
            warning: 'bi-exclamation-triangle-fill text-warning',
            danger:  'bi-x-circle-fill text-danger',
            info:    'bi-info-circle-fill text-info'
        };

        const id = 't_' + Date.now();
        container.insertAdjacentHTML('beforeend', `
            <div id="${id}" class="toast rx-panel border" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom" style="border-color: var(--rx-border) !important;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi ${iconMap[type] || iconMap.info}"></i>
                        <strong style="font-size: 0.86rem;">${title}</strong>
                    </div>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="px-3 py-2 small text-secondary">${message}</div>
            </div>
        `);
        const el = document.getElementById(id);
        const toast = new bootstrap.Toast(el, { delay: 3500 });
        toast.show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
    };
});
