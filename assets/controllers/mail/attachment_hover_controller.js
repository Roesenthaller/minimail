import { Controller } from "@hotwired/stimulus";

/**
 * The card that floats beside an attachment chip on a conversation row while
 * the pointer is on it.
 *
 * One card, built once and moved, rather than one per chip: a page holds fifty
 * rows and most of them have no file, and the few that do are looked at one at
 * a time. The card is appended to <body> and positioned with `position: fixed`
 * from the chip's rectangle, because the list scrolls and clips — a card drawn
 * inside the row would be cut off at the list's edge, which is exactly where
 * the last row's card would want to open.
 *
 * The chips are found by delegation on the controller's own element, from the
 * `data-attachment-chip` wrapper the partial puts around each one. What a chip
 * says about itself is in its data-* attributes:
 *
 *   data-name   the file's name
 *   data-meta   "14.3 KB · application/pdf", or empty
 *   data-icon   a Font Awesome class, for when there is no picture
 *   data-thumb  the thumbnail's URL, when one can be drawn
 *   data-opens  "preview" or "download", which is what a click will do
 *
 * Everything from those attributes is set as TEXT (or as an `src` on an <img>
 * we created), never as markup. The name is whatever the sender called the
 * file.
 *
 * Shown after a short delay, so moving the pointer across a row on the way to
 * somewhere else does not make a card flicker past, and hidden on anything
 * that leaves its anchor behind — the pointer going, the list scrolling, a
 * click, Escape, the controller being torn down by a Turbo render.
 *
 * Keyboard focus shows it as well as the pointer, because a chip is a button
 * and what a hover offers a mouse user a focus ring should offer the rest.
 * Touch has no hover, and a tap just does what the chip does.
 */
export default class extends Controller {
    static values = {
        previewHint: String,
        downloadHint: String,
    };

    /** Milliseconds the pointer rests on a chip before its card opens. */
    static DELAY = 250;

    /** The gap between the chip and its card. */
    static GAP = 6;

    connect() {
        this._timer = null;
        this._anchor = null;
        this._card = null;
        this._fills = 0;

        this._enter = (event) => this._schedule(event);
        this._leave = (event) => this._leaveChip(event);
        this._away = () => this.hide();
        this._escape = (event) => {
            if ("Escape" === event.key) {
                this.hide();
            }
        };

        this.element.addEventListener("mouseover", this._enter);
        this.element.addEventListener("mouseout", this._leave);
        this.element.addEventListener("focusin", this._enter);
        this.element.addEventListener("focusout", this._leave);
    }

    disconnect() {
        this.element.removeEventListener("mouseover", this._enter);
        this.element.removeEventListener("mouseout", this._leave);
        this.element.removeEventListener("focusin", this._enter);
        this.element.removeEventListener("focusout", this._leave);
        this.hide();
        this._card?.remove();
        this._card = null;
    }

    /** Also an action: a click on a chip is the end of the hover. */
    hide() {
        // A thumbnail still in flight belongs to a card that is going away.
        this._fills += 1;

        clearTimeout(this._timer);
        this._timer = null;
        this._anchor = null;

        if (null !== this._card) {
            this._card.hidden = true;
        }

        window.removeEventListener("scroll", this._away, true);
        window.removeEventListener("resize", this._away);
        document.removeEventListener("keydown", this._escape);
    }

    _chipOf(event) {
        return event.target instanceof Element
            ? event.target.closest("[data-attachment-chip]")
            : null;
    }

    _schedule(event) {
        const chip = this._chipOf(event);

        // Still on the chip it was already waiting for, or showing: nothing to do.
        if (null === chip || chip === this._anchor) {
            return;
        }

        this.hide();
        this._anchor = chip;
        this._timer = setTimeout(() => this._show(chip), this.constructor.DELAY);
    }

    _leaveChip(event) {
        const chip = this._chipOf(event);

        if (null === chip || chip !== this._anchor) {
            return;
        }

        // `mouseout` also fires when moving between a chip's own children;
        // only leaving the chip altogether counts.
        if (event.relatedTarget instanceof Node && chip.contains(event.relatedTarget)) {
            return;
        }

        this.hide();
    }

    _show(chip) {
        if (chip !== this._anchor || false === chip.isConnected) {
            return;
        }

        const card = this._build();
        this._fill(card, chip.dataset);

        card.hidden = false;
        this._place(card, chip);

        // Anything that moves the chip out from under the card closes it. The
        // list scrolls its own element, not the window, so `scroll` is caught
        // in the capture phase.
        window.addEventListener("scroll", this._away, true);
        window.addEventListener("resize", this._away);
        document.addEventListener("keydown", this._escape);
    }

    /** The card, created on first use and reused after. */
    _build() {
        if (null !== this._card) {
            return this._card;
        }

        const card = document.createElement("div");

        card.hidden = true;
        card.setAttribute("role", "tooltip");
        card.className = "popover fixed z-[60] w-56 max-w-[calc(100vw-1rem)] overflow-hidden pointer-events-none text-left";

        const picture = document.createElement("div");
        picture.dataset.part = "picture";
        picture.className = "flex items-center justify-center bg-sunken min-h-24 max-h-48 overflow-hidden";

        const text = document.createElement("div");
        text.className = "px-3 py-2";

        const name = document.createElement("p");
        name.dataset.part = "name";
        name.className = "text-sm font-medium text-ink break-words";

        const meta = document.createElement("p");
        meta.dataset.part = "meta";
        meta.className = "mt-0.5 text-xs text-ink-faint break-all";

        const hint = document.createElement("p");
        hint.dataset.part = "hint";
        hint.className = "mt-1.5 text-[11px] text-ink-soft";

        text.append(name, meta, hint);
        card.append(picture, text);
        document.body.append(card);

        this._card = card;

        return card;
    }

    _fill(card, data) {
        const picture = card.querySelector('[data-part="picture"]');
        const meta = card.querySelector('[data-part="meta"]');

        card.querySelector('[data-part="name"]').textContent = data.name ?? "";
        meta.textContent = data.meta ?? "";
        meta.hidden = !data.meta;
        card.querySelector('[data-part="hint"]').textContent =
            "preview" === data.opens ? this.previewHintValue : this.downloadHintValue;

        picture.replaceChildren(this._icon(data.icon));

        // Which fill this is. A thumbnail can arrive after the card has moved
        // on to another chip, and must not paint over that chip's own picture.
        const fill = ++this._fills;

        if (data.thumb) {
            // The icon stays until the picture has actually loaded, and stays
            // for good when it does not: a thumbnail that will not decode
            // answers 404, and a broken-image glyph is worse than an icon.
            const image = document.createElement("img");

            image.alt = "";
            image.decoding = "async";
            image.className = "block w-full h-auto max-h-48 object-contain";
            image.addEventListener("load", () => {
                if (fill !== this._fills || null === this._anchor || card.hidden) {
                    return;
                }

                picture.replaceChildren(image);
                this._place(card, this._anchor);
            });
            image.src = data.thumb;
        }
    }

    _icon(classes) {
        const wrapper = document.createElement("span");
        const icon = document.createElement("i");

        wrapper.className = "py-6";
        icon.className = `fa-solid ${classes || "fa-file"} text-4xl text-ink-faint`;
        icon.setAttribute("aria-hidden", "true");
        wrapper.append(icon);

        return wrapper;
    }

    /**
     * Below the chip, left edges aligned; above it when there is no room
     * below, and kept inside the window sideways. Measured after the card is
     * shown, because its height depends on what is in it.
     */
    _place(card, chip) {
        if (null === chip || false === chip.isConnected) {
            return;
        }

        const gap = this.constructor.GAP;
        const anchor = chip.getBoundingClientRect();
        const box = card.getBoundingClientRect();
        const margin = 8;

        let top = anchor.bottom + gap;

        if (top + box.height > window.innerHeight - margin && anchor.top - gap - box.height > margin) {
            top = anchor.top - gap - box.height;
        }

        const left = Math.max(margin, Math.min(anchor.left, window.innerWidth - box.width - margin));

        card.style.top = `${Math.max(margin, top)}px`;
        card.style.left = `${left}px`;
    }
}
