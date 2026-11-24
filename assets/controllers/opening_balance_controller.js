import { Controller } from '@hotwired/stimulus';

const decimal = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
const dateFormat = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'UTC' });

export default class extends Controller {
    static targets = ['date', 'status', 'amount', 'confirmed', 'key', 'recalculate'];
    static values = { url: String };

    disconnect() {
        this.request?.abort();
    }

    confirmAgain() {
        this.confirmedTarget.checked = false;
    }

    async estimate() {
        this.request?.abort();
        this.confirmAgain();
        this.amountTarget.value = '';
        this.keyTarget.value = '';
        this.amountTarget.disabled = true;
        this.confirmedTarget.disabled = true;
        const hired = this.dateTarget.value;
        this.recalculateTarget.hidden = !hired;
        this.dateTarget.setCustomValidity('');
        if (!hired) {
            this.statusTarget.textContent = 'Renseignez la date d’embauche pour calculer le solde proposé.';
            return;
        }
        const request = new AbortController();
        this.request = request;
        this.statusTarget.textContent = 'Calcul du solde initial…';
        this.dateTarget.setCustomValidity('Attendez le calcul du solde initial.');
        try {
            const url = new URL(this.urlValue, window.location.href);
            url.searchParams.set('hired', hired);
            const response = await fetch(url, { signal: request.signal });
            if (!response.ok) throw new Error();
            const estimate = await response.json();
            if (request.signal.aborted) return;
            const proposed = decimal.format(estimate.units / 2);
            const earned = decimal.format(estimate.earnedUnits / 2);
            const opens = dateFormat.format(new Date(estimate.opensOn + 'T00:00:00Z'));
            this.statusTarget.textContent = estimate.available
                ? `Proposition de ${proposed} j pour ${estimate.months} mois de service.`
                : `Proposition de ${proposed} j. Droits calculés de ${earned} j, disponibles à partir du ${opens}.`;
            this.amountTarget.value = estimate.units / 2;
            this.keyTarget.value = estimate.key;
            this.amountTarget.disabled = false;
            this.confirmedTarget.disabled = false;
            this.dateTarget.setCustomValidity('');
        } catch {
            if (request.signal.aborted) return;
            this.statusTarget.textContent = 'Le solde est indisponible. Vérifiez la date ou réessayez avec Recalculer.';
            this.dateTarget.setCustomValidity('Calculez le solde initial avant de poursuivre.');
        }
    }
}
