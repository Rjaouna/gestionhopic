import { Application, Controller } from '@hotwired/stimulus';

class LoginController extends Controller {
    static targets = ['openButton', 'formTitle', 'password', 'error'];
    static values = { failed: Boolean };

    connect() {
        this.element.dataset.loginView = this.failedValue ? 'form' : 'welcome';
    }

    open() {
        this.element.dataset.loginView = 'form';
        this.formTitleTarget.focus();
    }

    cancel() {
        this.passwordTarget.value = '';
        if (this.hasErrorTarget) this.errorTarget.hidden = true;
        this.element.dataset.loginView = 'welcome';
        this.openButtonTarget.focus();
    }
}

Application.start().register('login', LoginController);
