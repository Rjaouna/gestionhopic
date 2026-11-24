import { Controller } from '@hotwired/stimulus';

const localHeaders = { 'X-Requested-With': 'XMLHttpRequest' };
const decimal = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });

export default class extends Controller {
    connect() {
        this.page = this.element.querySelector('#contenu');
        this.modalElement = this.element.querySelector('#workspace-modal');
        this.modalContent = this.element.querySelector('[data-modal-content]');
        this.modalHistory = [];
        this.events = new AbortController();
        const { signal } = this.events;
        this.element.addEventListener('click', event => this.handleClick(event), { signal });
        this.element.addEventListener('submit', event => this.handleSubmit(event), { signal });
        this.element.addEventListener('change', event => this.handleChange(event), { signal });
        this.element.addEventListener('input', event => this.handleInput(event), { signal });
        this.element.addEventListener('error', event => {
            if (event.target instanceof HTMLImageElement && event.target.hasAttribute('data-inventory-photo')) {
                event.target.hidden = true;
            }
        }, { capture: true, signal });
        this.element.querySelectorAll('img[data-inventory-photo]').forEach(photo => {
            if (photo.complete && !photo.naturalWidth) photo.hidden = true;
        });
        window.addEventListener('popstate', () => this.loadPage(window.location.href), { signal });
        this.modalElement.addEventListener('hide.bs.modal', event => {
            if (this.saving && !this.success) {
                event.preventDefault();
            } else if (!this.success && (this.dirty || this.modalHistory.some(entry => entry.dirty))
                && !window.confirm('Fermer et perdre les modifications en cours ?')) {
                event.preventDefault();
            }
        }, { signal });
        this.modalElement.addEventListener('hidden.bs.modal', () => {
            this.formRequest?.abort();
            this.durationRequest?.abort();
            this.modalContent.replaceChildren();
            this.modalHistory.length = 0;
            this.dirty = false;
            this.success = false;
            if (this.modalTrigger?.isConnected) this.modalTrigger.focus();
            else this.page.focus({ preventScroll: true });
        }, { signal });
        this.modalElement.addEventListener('shown.bs.modal', () => {
            this.focusModalTitle();
        }, { signal });
        try {
            this.element.classList.toggle('sidebar-small', localStorage.getItem('tempo.sidebar') === 'small');
        } catch {
            // The sidebar remains available if storage is disabled.
        }
        if (window.Chart) this.drawChart();
        else window.addEventListener('load', () => this.drawChart(), { once: true, signal });
    }

    disconnect() {
        this.events?.abort();
        this.pageRequest?.abort();
        this.formRequest?.abort();
        this.durationRequest?.abort();
        this.chart?.destroy();
        this.releaseLogoPreview();
        clearTimeout(this.searchTimer);
    }

    toggleSidebar(event) {
        const small = this.element.classList.toggle('sidebar-small');
        const label = small ? 'Développer le menu' : 'Réduire le menu';
        event.currentTarget.setAttribute('aria-label', label);
        event.currentTarget.title = label;
        try { localStorage.setItem('tempo.sidebar', small ? 'small' : 'wide'); } catch {}
    }

    inventoryExpired(event) {
        const location = event.detail.location;
        if (!this.saving && this.modalTrigger?.closest('[data-inventory-expiring]')?.hidden) {
            window.bootstrap?.Modal.getInstance(this.modalElement)?.hide();
        }
        this.loadPage(location || window.location.href, { replace: Boolean(location), focus: Boolean(location) });
    }

    handleClick(event) {
        const blockedDeletion = event.target.closest('[data-employee-delete-reason]');
        if (blockedDeletion) {
            this.toast(blockedDeletion.dataset.employeeDeleteReason);
            return;
        }
        if (event.target.closest('[data-modal-back]')) {
            this.backModal();
            return;
        }
        const passwordToggle = event.target.closest('[data-password-toggle]');
        if (passwordToggle) {
            const input = passwordToggle.closest('.password-field').querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            const label = `${show ? 'Masquer' : 'Afficher'} ${passwordToggle.dataset.passwordLabel}`;
            passwordToggle.setAttribute('aria-pressed', String(show));
            passwordToggle.setAttribute('aria-label', label);
            passwordToggle.title = label;
            passwordToggle.querySelector('i').classList.toggle('bi-eye', !show);
            passwordToggle.querySelector('i').classList.toggle('bi-eye-slash', show);
            return;
        }
        const restore = event.target.closest('[data-reset-logo]');
        if (restore) {
            const form = restore.form;
            form.elements.logo.value = '';
            form.elements.resetLogo.value = '1';
            this.previewAppearance(form, form.elements.logo);
            return;
        }
        const trigger = event.target.closest('[data-modal-url]');
        if (trigger) {
            event.preventDefault();
            this.openModal(trigger);
            return;
        }
        const link = event.target.closest('a[data-nav]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (this.modalContent.contains(link)) {
            window.bootstrap.Modal.getInstance(this.modalElement)?.hide();
            if (this.modalElement.classList.contains('show')) return;
        }
        clearTimeout(this.searchTimer);
        this.loadPage(link.href, { push: true, focus: true });
        const sidebar = this.element.querySelector('#navigation');
        if (sidebar) window.bootstrap?.Offcanvas.getInstance(sidebar)?.hide();
    }

    handleSubmit(event) {
        if (event.target.matches('[data-modal-form], [data-appearance-form]')) {
            event.preventDefault();
            this.submitForm(event.target, event.submitter);
        } else if (event.target.matches('[data-filter]')) {
            event.preventDefault();
            clearTimeout(this.searchTimer);
            this.filter(event.target, true);
        }
    }

    handleChange(event) {
        const field = event.target;
        const form = field.closest('form');
        if (!form) return;
        if (form.hasAttribute('data-auto-filter') && field.matches('select')) {
            clearTimeout(this.searchTimer);
            this.filter(form, true);
        }
        if (form.hasAttribute('data-modal-form')) {
            this.dirty = true;
            this.updateLeaveForm(form, field);
            this.updateBalance(form);
        }
        if (form.hasAttribute('data-appearance-form')) this.previewAppearance(form, field);
    }

    handleInput(event) {
        const field = event.target;
        if (field.matches('[data-live-search]')) {
            clearTimeout(this.searchTimer);
            this.pageRequest?.abort();
            this.searchTimer = setTimeout(() => this.filter(field.form, true, field), 280);
        }
        if (field.form?.hasAttribute('data-modal-form')) {
            this.dirty = true;
            if (field.name === 'quantity') this.updateBalance(field.form);
        }
        if (field.form?.hasAttribute('data-appearance-form') && ['name', 'suffix'].includes(field.name)) {
            this.previewAppearance(field.form, field);
        }
    }

    filter(form, push, field = null) {
        const url = new URL(form.action, window.location.href);
        for (const [key, value] of new FormData(form)) {
            if (typeof value === 'string' && value.trim()) url.searchParams.set(key, value.trim());
        }
        this.loadPage(url.href, { push, focus: form.classList.contains('top-search'), searchField: field });
    }

    async loadPage(url, { push = false, replace = false, focus = false, searchField = null } = {}) {
        this.pageRequest?.abort();
        const request = new AbortController();
        this.pageRequest = request;
        const status = this.element.querySelector('[data-page-status]');
        status.textContent = 'Chargement…';
        this.page.setAttribute('aria-busy', 'true');
        this.element.classList.add('is-loading-page');
        const selection = searchField ? [searchField.selectionStart, searchField.selectionEnd] : null;
        try {
            const response = await fetch(url, {
                headers: { ...localHeaders, 'X-Tempo-Fragment': 'page' }, signal: request.signal,
            });
            this.checkResponse(response);
            const fragment = document.createElement('template');
            fragment.innerHTML = (await response.text()).trim();
            const next = fragment.content.querySelector('[data-page]');
            if (!next) throw new Error('Cette page est indisponible.');
            this.chart?.destroy();
            this.chart = null;
            this.releaseLogoPreview();
            this.page.replaceChildren(next);
            if (replace) history.replaceState({}, '', url);
            else if (push && window.location.href !== url) history.pushState({}, '', url);
            this.applyAppearance(JSON.parse(next.dataset.appearance), next.dataset.title);
            this.element.querySelector('[data-current-title]')?.replaceChildren(next.dataset.title);
            const pending = this.element.querySelector('[data-pending-count]');
            if (pending) {
                pending.hidden = !Number(next.dataset.pending);
                pending.textContent = next.dataset.pending;
            }
            this.element.querySelector('[data-unread-dot]').hidden = !Number(next.dataset.unread);
            this.element.querySelectorAll('a[data-section]').forEach(link => {
                const active = link.dataset.section === next.dataset.section;
                link.classList.toggle('is-active', active);
                if (active) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            this.drawChart();
            if (selection) {
                const nextSearch = next.querySelector('[data-live-search]');
                nextSearch?.focus({ preventScroll: true });
                nextSearch?.setSelectionRange(...selection);
            } else if (focus) {
                const anchor = new URL(url, window.location.href).hash.slice(1);
                const target = document.getElementById(anchor);
                if (target && next.contains(target)) {
                    target.scrollIntoView({ block: 'start' });
                    target.focus({ preventScroll: true });
                } else {
                    window.scrollTo(0, 0);
                    this.page.focus({ preventScroll: true });
                }
            }
            status.textContent = next.dataset.title + ' chargé.';
        } catch (error) {
            if (error.name !== 'AbortError') this.toast(this.errorMessage(error), true);
        } finally {
            if (this.pageRequest === request) {
                this.page.removeAttribute('aria-busy');
                this.element.classList.remove('is-loading-page');
            }
        }
    }

    async openModal(trigger) {
        if (this.saving) return;
        this.formRequest?.abort();
        const request = new AbortController();
        this.formRequest = request;
        const nested = this.modalContent.contains(trigger);
        const modal = window.bootstrap.Modal.getOrCreateInstance(this.modalElement);
        if (!nested) {
            this.modalTrigger = trigger;
            this.dirty = false;
            this.success = false;
            this.modalContent.innerHTML = `
                <header class="modal-header">
                    <div class="modal-frame modal-heading">
                        <div class="modal-heading-title">
                            <span class="modal-heading-icon"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span></span>
                            <h2 class="modal-title" id="workspace-modal-title" tabindex="-1">Chargement…</h2>
                        </div>
                        <button class="icon-button modal-close" type="button" data-bs-dismiss="modal" aria-label="Fermer">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                </header>
                <div class="modal-body" aria-busy="true"></div>`;
            modal.show(trigger);
        }
        try {
            const response = await fetch(trigger.dataset.modalUrl, { headers: localHeaders, signal: request.signal });
            this.checkResponse(response);
            const content = await response.text();
            if (request.signal.aborted) return;
            if (nested) {
                if (this.saving) return;
                this.modalHistory.push({
                    nodes: [...this.modalContent.childNodes],
                    scrollTop: this.modalContent.querySelector('.modal-body').scrollTop,
                    dirty: this.dirty,
                    trigger,
                });
                this.durationRequest?.abort();
                this.dirty = false;
            }
            this.modalContent.innerHTML = content;
            this.modalContent.querySelector('[data-modal-back]').hidden = !this.modalHistory.length;
            this.updateLeaveForm(this.modalContent.querySelector('[data-modal-form]'));
            this.focusModalTitle();
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (!nested) modal.hide();
            this.toast(this.errorMessage(error), true);
        }
    }

    backModal() {
        if (this.saving || !this.modalHistory.length) return;
        if (this.dirty && !window.confirm('Revenir en arrière et perdre les modifications en cours ?')) return;
        this.formRequest?.abort();
        this.durationRequest?.abort();
        const previous = this.modalHistory.pop();
        this.modalContent.replaceChildren(...previous.nodes);
        this.dirty = previous.dirty;
        this.modalContent.querySelector('.modal-body').scrollTop = previous.scrollTop;
        previous.trigger.focus({ preventScroll: true });
    }

    focusModalTitle() {
        this.modalContent.querySelector('#workspace-modal-title')?.focus({ preventScroll: true });
    }

    async submitForm(form, submitter) {
        if (this.saving || (submitter?.dataset.confirm && !window.confirm(submitter.dataset.confirm))) return;
        this.saving = true;
        const alert = form.querySelector('[data-form-error]');
        alert.hidden = true;
        const data = new FormData(form, submitter);
        const buttons = [...form.querySelectorAll('button[type="submit"]')];
        const original = submitter?.textContent;
        buttons.forEach(button => { button.disabled = true; });
        if (submitter) submitter.textContent = 'En cours…';
        try {
            const response = await fetch(form.action, { method: 'POST', body: data, headers: localHeaders });
            const result = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
            this.checkResponse(response, result.error);
            if (form.hasAttribute('data-modal-form')) {
                this.success = true;
                this.dirty = false;
                window.bootstrap.Modal.getInstance(this.modalElement)?.hide();
            } else {
                this.applyAppearance(result.appearance, this.page.querySelector('[data-page]').dataset.title);
            }
            this.toast(result.message || 'Modification enregistrée.');
            await this.loadPage(result.location || window.location.href, { replace: Boolean(result.location), focus: Boolean(result.location) });
        } catch (error) {
            alert.textContent = this.errorMessage(error);
            alert.hidden = false;
            alert.tabIndex = -1;
            alert.focus();
        } finally {
            this.saving = false;
            buttons.forEach(button => { button.disabled = false; });
            if (submitter) submitter.textContent = original;
        }
    }

    previewAppearance(form, field) {
        const fields = form.elements;
        const preview = form.querySelector('[data-brand-preview]');
        if (field === fields.logo) {
            this.releaseLogoPreview();
            const file = fields.logo.files[0];
            if (file) {
                fields.resetLogo.value = '0';
                this.logoPreviewUrl = URL.createObjectURL(file);
            }
        }
        const savedLogo = fields.resetLogo.value === '1' ? '' : form.dataset.currentLogo;
        const logoUrl = this.logoPreviewUrl || savedLogo;
        preview.dataset.palette = fields.theme.value;
        this.paintBrand(preview, {
            name: fields.namedItem('name').value.trim() || 'Nom de l’application',
            suffix: fields.suffix.value.trim(),
            logoUrl,
        });
        form.querySelector('[data-logo-picker-label]').textContent = fields.logo.files[0]?.name
            || (savedLogo ? 'Remplacer le logo' : 'Choisir une image');
        form.querySelector('[data-reset-logo]').hidden = !logoUrl;
    }

    releaseLogoPreview() {
        if (!this.logoPreviewUrl) return;
        URL.revokeObjectURL(this.logoPreviewUrl);
        this.logoPreviewUrl = null;
    }

    applyAppearance(appearance, pageTitle) {
        document.documentElement.dataset.theme = appearance.theme;
        document.title = `${pageTitle} · ${appearance.title}`;
        const themeColor = document.querySelector('meta[name="theme-color"]');
        themeColor.content = appearance.themeColor;
        document.querySelector('meta[name="description"]').content = `${appearance.title}, espace interne pour les congés et absences.`;
        document.querySelectorAll('[data-brand-icon]').forEach(link => {
            const target = appearance.logoUrl || '/tempo.png';
            if (link.getAttribute('href') !== target) link.href = target;
        });
        this.element.querySelectorAll('[data-live-brand]').forEach(brand => {
            this.paintBrand(brand, appearance);
            brand.setAttribute('aria-label', appearance.title);
            brand.title = appearance.title;
        });
    }

    paintBrand(container, appearance) {
        container.querySelector('[data-brand-name]').textContent = appearance.name;
        const suffix = container.querySelector('[data-brand-suffix]');
        suffix.textContent = appearance.suffix;
        suffix.hidden = !appearance.suffix;

        const image = container.querySelector('[data-brand-logo]');
        if (appearance.logoUrl) {
            if (image.getAttribute('src') !== appearance.logoUrl) image.src = appearance.logoUrl;
        } else {
            image.removeAttribute('src');
        }
        image.hidden = !appearance.logoUrl;
        container.querySelector('[data-brand-mark]').classList.toggle('has-logo', Boolean(appearance.logoUrl));
        container.querySelector('[data-brand-default]').hidden = Boolean(appearance.logoUrl);
    }

    async updateLeaveForm(form, field = null) {
        const panel = form?.querySelector('[data-duration-url]');
        if (!panel) return;
        const { start, end, employee, startSlot, endSlot, type, attachment } = form.elements;
        const halfDayToggle = panel.querySelector('[data-half-day-toggle]');
        if (!field || field === type || field === halfDayToggle) {
            const option = type.selectedOptions[0];
            const fields = panel.querySelector('[data-leave-fields]');
            fields.hidden = fields.disabled = !type.value;

            const allowsHalfDays = option?.dataset.halfDays === '1';
            panel.querySelector('[data-half-day-option]').hidden = !allowsHalfDays;
            halfDayToggle.disabled = !allowsHalfDays;
            if (!allowsHalfDays) halfDayToggle.checked = false;
            const showSlots = halfDayToggle.checked;
            panel.querySelector('[data-leave-slots]').hidden = !showSlots;
            startSlot.disabled = endSlot.disabled = !showSlots;
            if (!showSlots) {
                startSlot.value = 'morning';
                endSlot.value = 'afternoon';
            }

            const policy = option?.dataset.attachmentPolicy ?? 'none';
            const showAttachment = policy !== 'none';
            panel.querySelector('[data-leave-attachment]').hidden = !showAttachment;
            attachment.disabled = !showAttachment;
            attachment.required = policy === 'required';
            if (!showAttachment) attachment.value = '';
            panel.querySelector('[data-attachment-label]').textContent = attachment.required ? 'Obligatoire' : 'Facultatif';

            const counter = option?.dataset.counter;
            panel.querySelector('[data-duration-label]').textContent = counter
                ? `Durée décomptée · ${counter}`
                : 'Durée de l’absence';
            if (field === type) form.querySelector('[data-form-error]').hidden = true;
        }
        if (field === attachment || field?.name === 'comment') return;
        if (field === start && (!end.value || end.value < start.value)) end.value = start.value;
        end.min = start.value;
        this.durationRequest?.abort();
        const output = panel.querySelector('[data-duration]');
        output.textContent = '—';
        if (!type.value || !start.value || !end.value || (employee && !employee.value)) return;

        const request = new AbortController();
        this.durationRequest = request;
        const url = new URL(panel.dataset.durationUrl, window.location.href);
        for (const input of [type, start, end, startSlot, endSlot, employee]) {
            if (input && !input.disabled) url.searchParams.set(input.name, input.value);
        }
        try {
            const response = await fetch(url, { headers: localHeaders, signal: request.signal });
            this.checkResponse(response);
            const { units } = await response.json();
            if (!request.signal.aborted) {
                output.textContent = units === null ? '—' : `${decimal.format(units / 2)} j`;
            }
        } catch (error) {
            if (!request.signal.aborted) output.textContent = 'Indisponible';
        }
    }

    updateBalance(form) {
        const panel = form.querySelector('[data-balances]');
        if (!panel) return;
        const { employee, counter, quantity, operation } = form.elements;
        const current = panel.querySelector('[data-balance-now]');
        const projected = panel.querySelector('[data-balance-next]');
        if (!employee.value || !counter.value) {
            current.textContent = projected.textContent = '—';
            return;
        }
        const rows = JSON.parse(panel.dataset.balances);
        const row = rows.find(item => String(item.employee) === employee.value && String(item.counter) === counter.value);
        const value = (row?.units ?? 0) / 2;
        current.textContent = `${decimal.format(value)} j`;
        if (!quantity.value || !quantity.validity.valid) { projected.textContent = '—'; return; }
        const amount = quantity.valueAsNumber;
        const next = operation.value === 'set' ? amount : value + (operation.value === 'remove' ? -amount : amount);
        projected.textContent = `${decimal.format(next)} j`;
    }

    checkResponse(response, message) {
        if (response.redirected && new URL(response.url).pathname === '/connexion') {
            window.location.assign(response.url);
            throw new Error('Votre session a expiré.');
        }
        if (response.ok) return;
        const fallbacks = { 400: 'La requête est invalide.', 401: 'Veuillez vous reconnecter.', 403: 'Accès interdit.',
            404: 'Cet élément est introuvable.', 409: 'Une modification est en cours. Réessayez.', 422: 'Vérifiez les champs saisis.' };
        throw new Error(message || fallbacks[response.status] || 'Une erreur est survenue. Réessayez dans un instant.');
    }

    errorMessage(error) {
        return error instanceof TypeError ? 'Connexion indisponible. Vérifiez votre réseau puis réessayez.' : error.message;
    }

    toast(message, error = false) {
        const toast = document.createElement('div');
        toast.className = 'toast tempo-toast' + (error ? ' is-error' : '');
        toast.setAttribute('role', error ? 'alert' : 'status');
        toast.innerHTML = '<div class="d-flex align-items-center"><div class="toast-body"></div><button type="button" class="btn-close me-2 ms-auto" data-bs-dismiss="toast" aria-label="Fermer"></button></div>';
        toast.querySelector('.toast-body').textContent = message;
        this.element.querySelector('[data-toasts]').append(toast);
        toast.addEventListener('hidden.bs.toast', () => toast.remove(), { once: true });
        window.bootstrap?.Toast.getOrCreateInstance(toast, { delay: 4500 }).show();
    }

    drawChart() {
        const canvas = this.page.querySelector('[data-chart]');
        if (!canvas || !window.Chart || this.chart) return;
        const data = JSON.parse(canvas.dataset.chart);
        const style = getComputedStyle(canvas);
        const muted = style.getPropertyValue('--muted').trim();
        const line = style.getPropertyValue('--line').trim();
        const groups = [
            ['Congés payés', 'mint', style.getPropertyValue('--chart-main').trim()],
            ['RTT', 'violet', '#ae9bdb'],
            ['Autres absences', 'amber', '#e2b771'],
        ];
        this.chart = new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.map(({ label }) => label.charAt(0).toUpperCase() + label.slice(1)),
                datasets: groups.map(([label, key, backgroundColor]) => ({
                    label, data: data.map(row => row[key]), backgroundColor, borderRadius: 4,
                    borderSkipped: 'bottom', maxBarThickness: 17, categoryPercentage: .69, barPercentage: .82,
                })),
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: {
                    backgroundColor: style.getPropertyValue('--sidebar').trim(), padding: 10, cornerRadius: 8,
                    callbacks: { label: context => ` ${context.dataset.label} · ${decimal.format(context.raw)} j` },
                } },
                scales: {
                    x: { grid: { display: false }, border: { color: line }, ticks: { color: muted, font: { family: 'Inter', size: 11 }, maxRotation: 0 } },
                    y: { beginAtZero: true, suggestedMax: 12, grid: { color: line }, border: { display: false },
                        ticks: { color: muted, font: { family: 'Inter', size: 11 }, padding: 6, precision: 0, maxTicksLimit: 5 } },
                },
            },
        });
    }
}
