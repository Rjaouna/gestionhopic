import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content'];

    connect() {
        this.request = new AbortController();
        this.timer = setInterval(() => this.refresh(), 4000);
    }

    disconnect() {
        clearInterval(this.timer);
        this.request.abort();
    }

    async refresh() {
        if (this.loading || document.hidden || document.body.classList.contains('modal-open') || !this.contentTargets.length) return;
        this.loading = true;
        const url = window.location.href;
        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Tempo-Fragment': 'page' },
                signal: this.request.signal,
            });
            if (!response.ok || response.redirected) return;
            const fragment = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (!this.element.isConnected || window.location.href !== url || document.body.classList.contains('modal-open')) return;
            const { inventoryCampaign, inventoryAssignment } = this.element.dataset;
            const nextScope = fragment.querySelector('[data-inventory-campaign]')?.dataset;
            if (inventoryCampaign && (inventoryCampaign !== nextScope?.inventoryCampaign || inventoryAssignment !== nextScope?.inventoryAssignment)) {
                window.location.reload();
                return;
            }
            for (const target of this.contentTargets) {
                const next = fragment.getElementById(target.id);
                if (next && !target.contains(document.activeElement) && next.innerHTML !== target.innerHTML) {
                    const scroller = target.querySelector('.table-responsive');
                    const horizontalPosition = scroller?.scrollLeft;
                    target.innerHTML = next.innerHTML;
                    if (horizontalPosition) target.querySelector('.table-responsive')?.scrollTo({ left: horizontalPosition });
                }
            }
        } catch {
            // A later poll resumes when the page is reachable again.
        } finally {
            this.loading = false;
        }
    }
}
