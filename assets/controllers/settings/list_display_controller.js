import { Controller } from "@hotwired/stimulus";
import { requestFailed } from "../../request_errors.js";

/**
 * The three choices for how a list row looks: the size of the sender disc, the
 * colours it is drawn in, and the width of the sender column.
 *
 * Each radio group posts its own field to one endpoint and, once that has
 * landed, sets the matching data attribute on <html>, which app.css reads. The
 * strip of discs on this card uses the same stylesheet, so it shows exactly what
 * the list will do. A choice that did not save is put back, because there is no
 * Save button and the radio is what says what is stored.
 *
 * Values:
 *   url   - where the choice is remembered (POST, one field per call)
 *   token - the CSRF token for that endpoint
 *
 * Routes used:
 *   POST app_appearance_list_display_state
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    async pick(event) {
        const input = event.target;
        const group = input.closest("[role=radiogroup]");
        const previous = group.dataset.saved;

        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append(input.dataset.field, input.value);

        let response;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                body,
                headers: { "X-Requested-With": "fetch" },
            });
        } catch {
            this._revert(group, previous);
            requestFailed(null);

            return;
        }

        if (requestFailed(response)) {
            this._revert(group, previous);

            return;
        }

        group.dataset.saved = input.value;
        document.documentElement.setAttribute(input.dataset.attribute, input.value);
    }

    /** The Inbox-as-messages checkbox: a yes or no, posted like the radios. */
    async toggle(event) {
        const box = event.target;
        const body = new FormData();
        body.append("_token", this.tokenValue);
        body.append(box.name, box.checked ? "1" : "0");

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                body,
                headers: { "X-Requested-With": "fetch" },
            });

            if (requestFailed(response)) {
                box.checked = !box.checked;
            }
        } catch {
            box.checked = !box.checked;
            requestFailed(null);
        }
    }

    _revert(group, previous) {
        const back = group.querySelector(`input[value="${previous}"]`);

        if (null !== back) {
            back.checked = true;
        }
    }
}
