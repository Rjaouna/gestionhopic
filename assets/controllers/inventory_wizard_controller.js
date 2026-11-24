import { Controller } from '@hotwired/stimulus';

const integer = new Intl.NumberFormat('fr-FR');

export default class extends Controller {
    static targets = ['step', 'indicator', 'brand', 'category', 'scopeStatus', 'campaign', 'available',
        'summaryScope', 'summaryEmployee', 'summaryCount', 'back', 'next', 'confirm'];
    static values = { url: String };

    connect() {
        this.currentStep = 0;
        this.preview();
    }

    disconnect() {
        this.request?.abort();
    }

    async preview() {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        this.estimate = null;
        this.scopeStatusTarget.textContent = 'Calcul du périmètre…';
        this.scopeStatusTarget.removeAttribute('data-scope-completed');
        this.render();
        try {
            const url = new URL(this.urlValue, window.location.href);
            url.searchParams.set('marque', this.brandTarget.value);
            url.searchParams.set('categorie', this.categoryTarget.value);
            const response = await fetch(url, { signal: request.signal });
            if (!response.ok || response.redirected) throw new Error();
            const scope = await response.json();
            if (request.signal.aborted) return;
            this.estimate = scope;
            this.campaignTarget.value = scope.campaign;
            this.availableTarget.value = scope.available;
            if (scope.inventoried) {
                this.scopeStatusTarget.setAttribute('data-scope-completed', '');
                this.scopeStatusTarget.textContent = 'Tous les articles de ce périmètre ont déjà été inventoriés.';
            } else if (scope.available) {
                this.scopeStatusTarget.textContent = `${integer.format(scope.available)} article(s) à affecter · ${integer.format(scope.assigned)} déjà affecté(s).`;
            } else {
                this.scopeStatusTarget.textContent = 'Tous les articles de ce périmètre sont déjà affectés, ou le périmètre est vide.';
            }
        } catch {
            if (request.signal.aborted) return;
            this.scopeStatusTarget.textContent = 'Périmètre indisponible. Sélectionnez de nouveau une marque ou une catégorie.';
        }
        this.render();
    }

    next() {
        if (!this.estimate?.available) return;
        if (this.currentStep === 1) {
            const employee = this.element.elements.employee;
            if (!employee.reportValidity()) return;
            const scope = [this.brandTarget, this.categoryTarget]
                .filter(select => select.value !== '')
                .map(select => select.selectedOptions[0].textContent.trim());
            this.summaryScopeTarget.textContent = scope.join(' · ') || 'Tout le catalogue';
            this.summaryEmployeeTarget.textContent = employee.selectedOptions[0].textContent.trim();
            this.summaryCountTarget.textContent = integer.format(this.estimate.available);
        }
        this.currentStep = Math.min(this.currentStep + 1, 2);
        this.render();
    }

    back() {
        this.currentStep = Math.max(this.currentStep - 1, 0);
        if (this.currentStep === 0) this.preview();
        else this.render();
    }

    validate(event) {
        if (this.currentStep !== 2 || !this.estimate?.available) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    render() {
        this.stepTargets.forEach((step, index) => { step.hidden = index !== this.currentStep; });
        this.indicatorTargets.forEach((item, index) => {
            if (index === this.currentStep) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        this.backTarget.hidden = this.currentStep === 0;
        this.nextTarget.hidden = this.currentStep === 2;
        this.nextTarget.disabled = !this.estimate?.available;
        this.confirmTarget.hidden = this.currentStep !== 2;
    }
}
