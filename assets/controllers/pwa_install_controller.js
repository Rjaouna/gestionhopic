import { Controller } from '@hotwired/stimulus';

let installPrompt = null;

window.addEventListener('beforeinstallprompt', event => {
    if (!document.body.classList.contains('admin-layout')) return;
    event.preventDefault();
    installPrompt = event;
});

export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.displayMode = window.matchMedia('(display-mode: standalone)');
        this.displayChange = () => this.updateButton();
        this.displayMode.addEventListener('change', this.displayChange);
        this.updateButton();
    }

    disconnect() {
        this.displayMode.removeEventListener('change', this.displayChange);
    }

    async install(event) {
        if (!event.isTrusted || !installPrompt) return;
        event.stopPropagation();
        const prompt = installPrompt;
        installPrompt = null;
        const button = this.buttonTarget;
        button.disabled = true;
        try {
            await prompt.prompt();
            if ((await prompt.userChoice).outcome === 'accepted') this.markInstalled();
        } catch {
            button.disabled = false;
            // The workspace click handler opens the manual instructions after a failed native prompt.
            button.click();
            return;
        }
        button.disabled = false;
    }

    markInstalled() {
        installPrompt = null;
        this.installedHere = true;
        this.updateButton();
    }

    updateButton() {
        this.buttonTarget.hidden = Boolean(this.installedHere || this.displayMode.matches || navigator.standalone);
    }
}
