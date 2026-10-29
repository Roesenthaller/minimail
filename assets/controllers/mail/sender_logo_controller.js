import { Controller } from "@hotwired/stimulus";

/**
 * A sender's logo over the initial on a row's avatar.
 *
 * The badge starts invisible with the initial showing beneath it, and is shown
 * only once the picture has actually loaded. A domain with no icon answers 404,
 * which leaves the initial exactly where it was: there is never a broken-image
 * glyph and never a blank circle.
 *
 * `load` is also checked on connect, because an image the browser already has
 * can finish before this controller is attached, and then no event is coming.
 */
export default class extends Controller {
    static targets = ["image"];

    connect() {
        const image = this.imageTarget;

        if (image.complete) {
            this._settle();
        }
    }

    show() {
        this._settle();
    }

    drop() {
        this.element.remove();
    }

    _settle() {
        // `complete` is also true for an image that failed; a real picture has a size.
        if (this.imageTarget.naturalWidth > 0) {
            this.element.classList.remove("opacity-0");
        } else if (this.imageTarget.complete) {
            this.drop();
        }
    }
}
