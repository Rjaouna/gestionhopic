import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['close', 'finished', 'progress', 'instructions', 'zero'];
    static values = { token: String, remaining: Number };

    connect() {
        this.forms = new Map();
        this.saves = Promise.resolve();
        this.pending = 0;
    }

    disconnect() {
        // Keep a valid edit when the employee moves to the next filtered page.
        for (const form of this.forms.keys()) this.flushForm(form);
    }

    queue(event) {
        const form = event.target.form;
        const entry = this.entry(form);
        clearTimeout(entry.timer);
        entry.dirty = true;
        form.closest('.inventory-count-row').dataset.countState = 'counting';
        form.querySelector('[data-count-result]').textContent = 'Quantité à enregistrer';
        form.querySelector('button').disabled = !event.target.checkValidity();
        entry.timer = setTimeout(() => this.flushForm(form), 400);
        this.updateClose();
    }

    flush(event) {
        this.flushForm(event.target.form);
    }

    finish(event) {
        event.preventDefault();
        this.record(event.target, true);
    }

    flushForm(form) {
        const entry = this.entry(form);
        if (entry.dirty && form.elements.quantity.checkValidity()) this.record(form, false);
    }

    record(form, completed) {
        const entry = this.entry(form);
        const quantity = form.elements.quantity.value;
        clearTimeout(entry.timer);
        entry.dirty = false;
        this.pending += 1;
        this.updateClose();
        this.saves = this.saves.then(async () => {
            const result = form.querySelector('[data-count-result]');
            const button = form.querySelector('button');
            const row = form.closest('.inventory-count-row');
            try {
                const body = new URLSearchParams({
                    _token: this.tokenValue, quantity, completed: completed ? '1' : '0', version: form.dataset.version,
                });
                result.textContent = 'Enregistrement…';
                const response = await fetch(form.action, {
                    method: 'POST', body, keepalive: true,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
                if (!response.ok || response.redirected) throw new Error(data.error || 'Actualisez la page et réessayez.');
                form.dataset.version = data.version;
                const current = !entry.dirty && quantity === form.elements.quantity.value;
                if (current) {
                    row.dataset.countState = data.completed ? 'done' : 'counting';
                    button.disabled = data.completed;
                    result.textContent = data.completed ? 'Terminé' : 'En cours · enregistré';
                    result.removeAttribute('data-error');
                }
                this.remainingValue = data.remaining;
                if (this.element.isConnected) {
                    this.finishedTarget.textContent = data.finished;
                    this.progressTarget.value = data.finished;
                }
            } catch (error) {
                entry.dirty = true;
                result.textContent = error instanceof TypeError ? 'Connexion interrompue. Réessayez.' : error.message;
                result.setAttribute('data-error', '');
                button.disabled = false;
            } finally {
                this.pending -= 1;
                this.updateClose();
            }
        });
    }

    entry(form) {
        if (!this.forms.has(form)) this.forms.set(form, { dirty: false, timer: null });
        return this.forms.get(form);
    }

    updateClose() {
        if (!this.element.isConnected || !this.hasCloseTarget) return;
        const saving = this.pending > 0 || Array.from(this.forms.values()).some(entry => entry.dirty);
        const ready = this.remainingValue === 0 && !saving;
        this.closeTarget.disabled = !ready;
        this.zeroTarget.disabled = saving;
        this.zeroTarget.hidden = this.remainingValue === 0;
        this.instructionsTarget.textContent = ready
            ? 'Tous les articles sont terminés. Vous pouvez clôturer votre inventaire.'
            : 'Saisissez le nombre compté, puis terminez chaque article.';
    }
}
