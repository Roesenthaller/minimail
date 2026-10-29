import { Controller } from "@hotwired/stimulus";
import { askConfirm } from "../../confirm.js";
import { jsonCsrfHeaders } from "../../csrf.js";
import { requestFailed } from "../../request_errors.js";
import { showToast } from "../../toast.js";

/**
 * The reading pane's Unsubscribe button, for the one-click kind (RFC 8058).
 *
 * The other two kinds the button can be — the sender's page and the composer —
 * are plain links and have no controller. This one exists because the server
 * makes the request, and a request made on somebody's behalf to an address a
 * stranger wrote is asked about first: the confirmation names the host, so what
 * is about to happen is stated rather than implied.
 *
 * The sentences come from the markup as values, because the page already holds
 * the user's language and a second translation table in script would be a
 * second thing to keep in step. Same arrangement as the delete-forever
 * confirmation on a list row.
 *
 * The button is disabled while the request is out, which is what stops a
 * second click sending a second request to a sender that has already said yes.
 * It is left disabled after a success, because there is nothing more to do
 * here, and re-enabled after a failure, because the reader may want to retry
 * or use the sender's page instead.
 */
export default class extends Controller {
    static values = {
        url: String,
        confirm: String,
        sent: String,
        failed: String,
    };

    async oneClick(event) {
        event.preventDefault();
        event.stopPropagation();

        if (false === await askConfirm(this.confirmValue)) {
            return;
        }

        this.element.disabled = true;

        let response;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: "{}",
            });
        } catch {
            requestFailed(null);
            this.element.disabled = false;

            return;
        }

        if (requestFailed(response)) {
            this.element.disabled = false;

            return;
        }

        let accepted = false;

        try {
            accepted = true === (await response.json()).ok;
        } catch {
            // An answer that is not the JSON we sent: treated as a refusal
            // rather than guessed at. The reader is offered nothing false.
        }

        if (accepted) {
            showToast(this.sentValue, { type: "success" });

            return;
        }

        showToast(this.failedValue, { type: "error" });
        this.element.disabled = false;
    }
}
