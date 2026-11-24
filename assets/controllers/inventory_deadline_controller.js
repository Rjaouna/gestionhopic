import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['clock'];
    static values = { location: String };

    connect() {
        if (!this.hasClockTarget) return;
        const now = Date.now();
        this.deadlines = this.clockTargets.map(clock => ({ clock, endsAt: now + Number(clock.dataset.seconds) * 1000 }));
        this.timer = setInterval(() => this.tick(), 250);
        this.tick();
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick() {
        let expired = false;
        const now = Date.now();
        for (const { clock, endsAt } of this.deadlines) {
            const seconds = Math.max(0, Math.ceil((endsAt - now) / 1000));
            const display = [Math.floor(seconds / 60), seconds % 60].map(value => String(value).padStart(2, '0')).join(':');
            if (clock.textContent !== display) {
                clock.textContent = display;
                clock.dateTime = `PT${seconds}S`;
            }
            if (seconds === 0) {
                const item = clock.closest('[data-inventory-expiring]');
                if (item) item.hidden = true;
                expired = true;
            }
        }
        if (expired) {
            this.deadlines = this.deadlines.filter(({ endsAt }) => endsAt > now);
            if (!this.deadlines.length) clearInterval(this.timer);
            this.dispatch('expired', { detail: { location: this.locationValue } });
        }
    }
}
