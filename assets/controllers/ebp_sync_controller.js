import { Controller } from '@hotwired/stimulus';

const activeStates = new Set(['queued', 'running', 'waiting']);
const number = new Intl.NumberFormat('fr-FR');
const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });

export default class extends Controller {
    static targets = ['form', 'source', 'photos', 'edit', 'editActions', 'test', 'start',
        'notice', 'stage', 'message', 'progress', 'lastImport', 'lastCount'];
    static values = { url: String, status: Object };

    connect() {
        this.requests = new AbortController();
        this.current = this.statusValue;
        this.render();
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.requests.abort();
    }

    edit() {
        this.setEditing(true);
        this.sourceTarget.focus();
    }

    cancel() {
        this.sourceTarget.value = this.current.sourceUrl;
        this.photosTarget.value = this.current.photoPath;
        this.setEditing(false);
        this.editTarget.focus();
    }

    setEditing(editing) {
        this.editing = editing;
        this.sourceTarget.readOnly = !editing;
        this.photosTarget.readOnly = !editing;
        this.editActionsTarget.hidden = !editing;
        this.render();
    }

    async submit(event) {
        event.preventDefault();
        const operation = event.submitter?.value;
        if (!operation || this.busy) return;
        if (operation === 'enqueue') {
            this.pendingStart = true;
            await this.start();
            return;
        }
        this.busy = true;
        this.render();
        this.showNotice(operation === 'test' ? 'Vérification du fichier CSV…' : 'Enregistrement de la connexion…');
        try {
            const result = await this.request(operation);
            if (operation === 'save') {
                this.current = result;
                this.sourceTarget.value = result.sourceUrl;
                this.photosTarget.value = result.photoPath;
                this.setEditing(false);
                this.showNotice('Les réglages de connexion sont enregistrés.', 'success');
            } else {
                this.showNotice(`Connexion réussie · ${number.format(result.count)} articles, ${result.categories} catégories et ${result.brands} marques.`, 'success');
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.showNotice(error instanceof TypeError ? 'Le serveur de l’application ne répond pas.' : error.message, 'error');
            }
        } finally {
            this.busy = false;
            if (this.element.isConnected) {
                this.render();
                this.schedule();
            }
        }
    }

    async start() {
        if (this.busy || !this.pendingStart) return;
        if (!navigator.onLine) {
            this.pause();
            return;
        }
        this.busy = true;
        this.render();
        try {
            this.current = await this.request('enqueue');
            this.pendingStart = false;
            this.hideNotice();
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (error instanceof TypeError) {
                this.showNotice('Le serveur de l’application est momentanément inaccessible. Nouvelle tentative automatique.', 'waiting');
            } else {
                this.pendingStart = false;
                this.showNotice(error.message, 'error');
            }
        } finally {
            this.busy = false;
            if (this.element.isConnected) {
                this.render();
                this.schedule(this.pendingStart ? 6000 : 0);
            }
        }
    }

    pause() {
        clearTimeout(this.timer);
        if (this.pendingStart || activeStates.has(this.current.state)) {
            this.showNotice('Connexion interrompue. La synchronisation reprendra à la reconnexion.', 'waiting');
        }
    }

    resume() {
        this.hideNotice();
        this.schedule(0);
    }

    schedule(delay = null) {
        clearTimeout(this.timer);
        if (!this.element.isConnected || !navigator.onLine || this.busy || this.working) return;
        if (this.pendingStart) {
            this.timer = setTimeout(() => this.start(), delay ?? 0);
            return;
        }
        if (!activeStates.has(this.current.state)) return;
        const wait = this.current.state === 'waiting'
            ? Math.max(0, this.current.nextAttemptAt * 1000 - Date.now())
            : (this.current.state === 'running' ? 3000 : 0);
        this.timer = setTimeout(() => this.work(), delay ?? wait);
    }

    async work() {
        if (this.working || !navigator.onLine || !activeStates.has(this.current.state)) return;
        this.working = true;
        this.stageTarget.textContent = 'Téléchargement et validation du CSV';
        this.messageTarget.textContent = 'Le catalogue sera mis à jour après la validation du fichier.';
        this.progressTarget.hidden = false;
        this.progressTarget.removeAttribute('value');
        let retryDelay = null;
        let retry = true;
        try {
            this.current = await this.request('run');
            this.hideNotice();
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (error instanceof TypeError) {
                retryDelay = 6000;
                this.showNotice('Le serveur de l’application est momentanément inaccessible. Nouvelle tentative automatique.', 'waiting');
            } else {
                retry = false;
                this.showNotice(error.message, 'error');
            }
        } finally {
            this.working = false;
            if (this.element.isConnected) {
                this.render();
                if (retry) this.schedule(retryDelay);
            }
        }
    }

    async request(operation) {
        const form = new FormData(this.formTarget);
        form.set('operation', operation);
        const response = await fetch(this.urlValue, {
            method: 'POST', body: form, signal: this.requests.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
        if (!response.ok || response.redirected) {
            throw new Error(data.error || 'La mise à jour n’a pas abouti. Vérifiez la connexion puis réessayez.');
        }

        return data;
    }

    render() {
        const status = this.current;
        const active = activeStates.has(status.state);
        const stages = {
            idle: 'Aucune mise à jour en cours',
            queued: 'Mise à jour en attente',
            running: 'Téléchargement et validation du CSV',
            waiting: 'Reprise automatique programmée',
            failed: 'Mise à jour interrompue',
            success: 'Catalogue à jour',
        };
        this.stageTarget.textContent = stages[status.state];
        this.messageTarget.textContent = status.state === 'waiting'
            ? `${status.message} Prochain essai à ${dateTime.format(status.nextAttemptAt * 1000)}.`
            : status.message;
        this.progressTarget.hidden = !active && status.state !== 'success';
        if (status.state === 'success') this.progressTarget.value = 100;
        else this.progressTarget.removeAttribute('value');
        this.progressTarget.dataset.state = status.state;
        this.lastImportTarget.textContent = status.lastImportAt ? dateTime.format(status.lastImportAt * 1000) : '—';
        this.lastCountTarget.textContent = status.lastImportAt ? number.format(status.lastCount) : '—';
        this.testTarget.disabled = this.busy || active;
        this.startTarget.disabled = this.busy || active || this.editing || this.pendingStart;
        this.editTarget.disabled = this.busy || this.editing;
        this.editActionsTarget.querySelector('[type="submit"]').disabled = this.busy;
    }

    showNotice(message, tone = 'info') {
        this.noticeTarget.textContent = message;
        this.noticeTarget.dataset.tone = tone;
        this.noticeTarget.hidden = false;
    }

    hideNotice() {
        this.noticeTarget.hidden = true;
    }
}
