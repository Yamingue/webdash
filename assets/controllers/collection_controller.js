import { Controller } from '@hotwired/stimulus';

// Collection de formulaire Symfony : ajout / suppression de lignes à partir du prototype.
export default class extends Controller {
    static targets = ['list'];
    static values = { prototype: String, index: Number };

    add() {
        const html = this.prototypeValue.replace(/__name__/g, this.indexValue++);
        this.listTarget.insertAdjacentHTML('beforeend', html);
    }

    remove(event) {
        event.target.closest('[data-collection-item]').remove();
    }
}
