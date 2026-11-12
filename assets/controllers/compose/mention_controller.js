import { Controller } from '@hotwired/stimulus';

/**
 * Typing @ and a name in the message body offers your contacts. Choosing one
 * writes their name into the text as a link to their address and adds them to
 * Cc, so they actually receive the message. An email is only delivered to the
 * people in To, Cc and Bcc, which is why "@hannah" on its own reached nobody.
 *
 * Sits on the same element as the compose controller and reads its rich body
 * (the contenteditable) and its address fields. The plain-text body has no
 * such field, so it does nothing there.
 *
 * Contacts come from the same endpoint the topbar search uses
 * (/contacts/autocomplete), so they are the people you have mailed and your
 * address books.
 */
export default class extends Controller {
    connect() {
        this._body = this.element.querySelector('[data-compose--compose-target="body"]');

        if (null === this._body) {
            return;
        }

        this._results = [];
        this._active = 0;
        this._seq = 0;
        this._timer = null;
        this._menu = null;

        this._onInput = this._handleInput.bind(this);
        this._onKeydown = this._handleKeydown.bind(this);
        this._onBlur = () => setTimeout(() => this._close(), 150);

        this._body.addEventListener('input', this._onInput);
        this._body.addEventListener('keydown', this._onKeydown);
        this._body.addEventListener('blur', this._onBlur);
    }

    disconnect() {
        if (null === this._body) {
            return;
        }

        this._body.removeEventListener('input', this._onInput);
        this._body.removeEventListener('keydown', this._onKeydown);
        this._body.removeEventListener('blur', this._onBlur);
        clearTimeout(this._timer);
        this._close();
    }

    // ── Detecting "@name" before the caret ────────────────────────────────

    /** The "@word" ending at the caret, or null. */
    _pending() {
        const selection = window.getSelection();

        if (null === selection || 0 === selection.rangeCount || false === selection.isCollapsed) {
            return null;
        }

        const range = selection.getRangeAt(0);
        const node = range.startContainer;

        if (Node.TEXT_NODE !== node.nodeType || false === this._body.contains(node)) {
            return null;
        }

        const before = node.textContent.slice(0, range.startOffset);
        const match = /(?:^|[\s ])@([^\s@ ]{1,30})$/.exec(before);

        return null === match ? null : { node, query: match[1], end: range.startOffset };
    }

    _handleInput() {
        clearTimeout(this._timer);

        const pending = this._pending();

        if (null === pending) {
            this._close();

            return;
        }

        this._timer = setTimeout(() => this._search(pending.query), 120);
    }

    async _search(query) {
        const seq = ++this._seq;
        let results = [];

        try {
            const response = await fetch(`/contacts/autocomplete?q=${encodeURIComponent(query)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (true === response.ok) {
                results = await response.json();
            }
        } catch (_) {
            results = [];
        }

        // A later keystroke already asked again, or the @ is gone.
        if (seq !== this._seq || null === this._pending()) {
            return;
        }

        this._results = results.slice(0, 6);
        this._active = 0;

        if (0 === this._results.length) {
            this._close();

            return;
        }

        this._render();
    }

    // ── The list ──────────────────────────────────────────────────────────

    _render() {
        if (null === this._menu) {
            this._menu = document.createElement('div');
            this._menu.setAttribute('role', 'listbox');
            this._menu.className = 'fixed z-[70] min-w-60 max-w-sm rounded-lg border border-line bg-surface py-1 text-sm shadow-lg';
            document.body.appendChild(this._menu);
        }

        this._menu.replaceChildren(...this._results.map((contact, index) => {
            const row = document.createElement('div');
            row.setAttribute('role', 'option');
            row.className = 'flex cursor-pointer items-baseline gap-2 px-3 py-1.5 '
                + (index === this._active ? 'bg-hover text-ink' : 'text-ink-soft');

            const name = document.createElement('span');
            name.className = 'truncate font-medium';
            name.textContent = contact.displayName || contact.email;
            row.appendChild(name);

            if (contact.displayName) {
                const email = document.createElement('span');
                email.className = 'truncate text-xs text-ink-faint';
                email.textContent = contact.email;
                row.appendChild(email);
            }

            // mousedown, not click: the click would first blur the body and
            // lose the caret this has to write at.
            row.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this._choose(index);
            });

            return row;
        }));

        const rect = window.getSelection()?.getRangeAt(0)?.getBoundingClientRect();

        if (rect) {
            const top = rect.bottom + 4;
            const left = Math.min(rect.left, window.innerWidth - 340);

            this._menu.style.top = `${Math.min(top, window.innerHeight - 260)}px`;
            this._menu.style.left = `${Math.max(8, left)}px`;
        }
    }

    _close() {
        this._results = [];
        this._seq += 1;
        this._menu?.remove();
        this._menu = null;
    }

    _handleKeydown(event) {
        if (null === this._menu) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                this._active = (this._active + 1) % this._results.length;
                break;
            case 'ArrowUp':
                this._active = (this._active - 1 + this._results.length) % this._results.length;
                break;
            case 'Enter':
            case 'Tab':
                event.preventDefault();
                event.stopPropagation();
                this._choose(this._active);

                return;
            case 'Escape':
                event.stopPropagation();
                this._close();

                return;
            default:
                return;
        }

        event.preventDefault();
        event.stopPropagation();
        this._render();
    }

    // ── Choosing someone ──────────────────────────────────────────────────

    _choose(index) {
        const contact = this._results[index];
        const pending = this._pending();

        if (undefined === contact || null === pending) {
            this._close();

            return;
        }

        const range = document.createRange();
        range.setStart(pending.node, pending.end - pending.query.length - 1);
        range.setEnd(pending.node, pending.end);
        range.deleteContents();

        const link = document.createElement('a');
        link.href = `mailto:${contact.email}`;
        link.textContent = `@${contact.displayName || contact.email}`;

        const space = document.createTextNode(' ');
        const fragment = document.createDocumentFragment();
        fragment.append(link, space);
        range.insertNode(fragment);

        const selection = window.getSelection();
        selection.removeAllRanges();
        const after = document.createRange();
        after.setStartAfter(space);
        after.collapse(true);
        selection.addRange(after);

        // The compose controller autosaves on input.
        this._body.dispatchEvent(new Event('input', { bubbles: true }));

        this._addToCc(contact.email);
        this._close();
    }

    /** Put the person in Cc unless they are already a recipient. */
    _addToCc(email) {
        const wanted = email.toLowerCase();
        const selects = ['toField', 'ccField', 'bccField']
            .map((name) => this.element.querySelector(`[data-compose--compose-target="${name}"] select`))
            .filter((select) => null !== select && undefined !== select.tomselect)
            .map((select) => select.tomselect);

        const already = selects.some((ts) => ts.items.some((value) => {
            const option = ts.options[value];
            const text = String(option?.text ?? option?.name ?? value).toLowerCase();

            return text.includes(wanted);
        }));

        if (true === already) {
            return;
        }

        const cc = this.element.querySelector('[data-compose--compose-target="ccField"] select')?.tomselect;

        if (undefined === cc) {
            return;
        }

        this.application.getControllerForElementAndIdentifier(this.element, 'compose--compose')?.showCc();
        cc.createItem(email, false);
    }
}
