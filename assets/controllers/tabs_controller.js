import { Controller } from '@hotwired/stimulus';

// Onglets accessibles : boutons role="tab" + panneaux role="tabpanel" (le 2e panneau est masqué côté serveur).
// L'état visuel s'appuie sur aria-selected (variantes Tailwind aria-selected:*).
export default class extends Controller {
    static targets = ['tab', 'panel'];

    show(event) {
        this.select(this.tabTargets.indexOf(event.currentTarget));
    }

    // Navigation au clavier : flèches gauche/droite, Début, Fin.
    key(event) {
        const current = this.tabTargets.findIndex((tab) => tab.getAttribute('aria-selected') === 'true');
        const last = this.tabTargets.length - 1;
        const next = {
            ArrowRight: current === last ? 0 : current + 1,
            ArrowLeft: current === 0 ? last : current - 1,
            Home: 0,
            End: last,
        }[event.key];
        if (next === undefined) {
            return;
        }
        event.preventDefault();
        this.select(next);
        this.tabTargets[next].focus();
    }

    select(index) {
        this.tabTargets.forEach((tab, i) => {
            tab.setAttribute('aria-selected', String(i === index));
            tab.tabIndex = i === index ? 0 : -1;
        });
        this.panelTargets.forEach((panel, i) => panel.toggleAttribute('hidden', i !== index));
    }
}
