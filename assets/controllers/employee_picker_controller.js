import { Controller } from '@hotwired/stimulus';

const resultLimit = 30;
const fold = value => value.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('fr');

export default class extends Controller {
    static targets = ['label', 'select', 'enhanced', 'trigger', 'choice', 'panel', 'search', 'clear', 'results', 'status', 'error'];

    connect() {
        this.employees = Array.from(this.selectTarget.options)
            .filter(option => option.value && !option.disabled)
            .map(option => {
                const name = option.text;
                const details = [option.dataset.code, option.dataset.department].filter(Boolean).join(' · ');
                return { id: option.value, name, details, search: fold(`${name} ${details}`) };
            });
        this.labelTarget.htmlFor = this.triggerTarget.id;
        this.selectTarget.hidden = true;
        this.enhancedTarget.hidden = false;
        this.syncSelection();
    }

    disconnect() {
        this.close();
        this.selectTarget.hidden = false;
        this.enhancedTarget.hidden = true;
        this.labelTarget.htmlFor = this.selectTarget.id;
    }

    syncSelection() {
        const selected = this.selectTarget.selectedOptions[0];
        const hasSelection = Boolean(selected?.value);
        this.choiceTarget.textContent = selected?.dataset.code
            ? `${selected.text} · ${selected.dataset.code}`
            : selected?.text || 'Choisir un collaborateur';
        this.triggerTarget.dataset.empty = String(!hasSelection);
        this.clearTarget.hidden = !hasSelection;
        if (hasSelection) {
            this.errorTarget.hidden = true;
            this.triggerTarget.removeAttribute('aria-invalid');
        }
    }

    toggle() {
        if (this.panelTarget.hidden) this.open();
        else this.close(true);
    }

    open() {
        this.searchTarget.value = '';
        this.panelTarget.hidden = false;
        this.triggerTarget.setAttribute('aria-expanded', 'true');
        this.searchTarget.setAttribute('aria-expanded', 'true');
        this.renderOptions();
        this.searchTarget.focus();
    }

    close(restoreFocus = false) {
        this.panelTarget.hidden = true;
        this.triggerTarget.setAttribute('aria-expanded', 'false');
        this.searchTarget.setAttribute('aria-expanded', 'false');
        this.searchTarget.removeAttribute('aria-activedescendant');
        if (restoreFocus) this.triggerTarget.focus({ preventScroll: true });
    }

    closeOutside(event) {
        if (!this.panelTarget.hidden && !this.element.contains(event.target)) this.close();
    }

    showRequired(event) {
        event.preventDefault();
        this.errorTarget.hidden = false;
        this.triggerTarget.setAttribute('aria-invalid', 'true');
        if (this.panelTarget.hidden) this.open();
        else this.searchTarget.focus();
    }

    searchInput(event) {
        event.stopPropagation();
        if (event.type === 'input') this.renderOptions();
    }

    renderOptions() {
        const terms = fold(this.searchTarget.value.trim()).split(/\s+/).filter(Boolean);
        const matches = this.employees.filter(employee => terms.every(term => employee.search.includes(term)));
        this.resultsTarget.replaceChildren(...matches.slice(0, resultLimit).map(employee => this.renderEmployee(employee)));
        this.statusTarget.classList.toggle('visually-hidden', matches.length > 0 && matches.length <= resultLimit);
        if (!matches.length) this.statusTarget.textContent = 'Aucun collaborateur trouvé.';
        else if (matches.length > resultLimit) {
            this.statusTarget.textContent = `${resultLimit} résultats sur ${matches.length}. Affinez la recherche pour voir les autres.`;
        } else this.statusTarget.textContent = `${matches.length} résultat${matches.length > 1 ? 's' : ''}.`;
        this.highlight(this.resultsTarget.firstElementChild);
    }

    renderEmployee(employee) {
        const option = document.createElement('div');
        const selected = this.selectTarget.value === employee.id;
        option.id = `${this.resultsTarget.id}-${employee.id}`;
        option.className = 'employee-picker-option';
        option.dataset.employeeValue = employee.id;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', String(selected));

        const name = document.createElement('strong');
        name.textContent = employee.name;
        const details = document.createElement('small');
        details.textContent = employee.details;
        details.hidden = !employee.details;
        option.append(name, details);
        if (selected) {
            const check = document.createElement('i');
            check.className = 'bi bi-check-lg';
            check.setAttribute('aria-hidden', 'true');
            option.append(check);
        }

        return option;
    }

    highlight(option) {
        this.activeOption?.classList.remove('is-active');
        this.activeOption = option;
        if (option) {
            option.classList.add('is-active');
            this.searchTarget.setAttribute('aria-activedescendant', option.id);
        } else this.searchTarget.removeAttribute('aria-activedescendant');
    }

    navigate(event) {
        const arrow = event.key === 'ArrowDown' || event.key === 'ArrowUp';
        if (event.target === this.triggerTarget && arrow) {
            event.preventDefault();
            if (this.panelTarget.hidden) this.open();
            else this.searchTarget.focus();
            return;
        }
        if (this.panelTarget.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close(true);
            return;
        }
        if (event.target !== this.searchTarget) return;
        if (event.key === 'Enter') {
            event.preventDefault();
            if (this.activeOption) this.chooseOption(this.activeOption);
        } else if (arrow) {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? this.activeOption?.nextElementSibling : this.activeOption?.previousElementSibling;
            if (next) {
                this.highlight(next);
                next.scrollIntoView({ block: 'nearest' });
            }
        }
    }

    choose(event) {
        const option = event.target.closest('[data-employee-value]');
        if (option) this.chooseOption(option);
    }

    keepSearchFocus(event) {
        if (event.target.closest('[data-employee-value]')) event.preventDefault();
    }

    chooseOption(option) {
        this.setSelection(option.dataset.employeeValue);
        this.close(true);
    }

    clearSelection() {
        this.setSelection('');
        this.searchTarget.value = '';
        this.renderOptions();
        this.searchTarget.focus();
    }

    setSelection(value) {
        if (this.selectTarget.value === value) return;
        this.selectTarget.value = value;
        this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
