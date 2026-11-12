import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * Expands an account's folder list in the sidebar. Accounts open and close
 * independently, so several can be showing at once.
 *
 * Which row is highlighted is not decided here. It used to be, in a
 * highlightActive() that compared pathnames and ignored the `?account=` these
 * links carry — so the same label lit up under every account at once. The
 * sidebar controller owns that for every nav row, this one included.
 */
export default class extends Controller {
    static targets = ["frame", "chevron", "toggle"];
    static values = { foldersUrl: String, persistUrl: String, account: Number, active: Boolean };

    toggle() {
        if (this.frameTarget.classList.contains("hidden")) {
            this._open();
            this._remember(true);

            return;
        }

        this._close();
        this._remember(false);
    }

    stop(event) {
        event.stopPropagation();
    }

    // ── Private ───────────────────────────────────────────────────────────

    _open() {
        this.frameTarget.classList.remove("hidden");
        this.chevronTarget.classList.add("rotate-90");
        this._announce(true);

        if (!this.frameTarget.src) {
            this.frameTarget.src = this.foldersUrlValue;
        }
    }

    _close() {
        this.frameTarget.classList.add("hidden");
        this.chevronTarget.classList.remove("rotate-90");
        this._announce(false);
    }

    /**
     * Keep aria-expanded in step with the class the chevron turns on.
     *
     * The button is rendered with the state the server knows about, so this
     * only has to cover the change made here — but it has to cover it, because
     * a disclosure button whose aria-expanded is stuck at its initial value is
     * worse than one that never had the attribute.
     */
    _announce(expanded) {
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute("aria-expanded", expanded ? "true" : "false");
        }
    }

    /**
     * Tell the server whether this account is open, so the next render already has
     * the folder list in it.
     *
     * Fire and forget, with keepalive: the click that expands an account is
     * often the click that navigates, and the request has to outlive the page
     * it was made from. A preference that fails to save is not worth
     * interrupting anyone over — the list is open either way, it just will not
     * be next time.
     */
    _remember(expanded) {
        if (false === this.hasPersistUrlValue) {
            return;
        }

        fetch(this.persistUrlValue, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify({ account: this.accountValue, expanded }),
            keepalive: true,
        }).catch(() => {});
    }
}
