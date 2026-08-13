import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';
import type {ChoiceOption} from './choice-block.js';
import {buildPreviewUrl, buildThumbnailUrl} from './thumbnail.js';
import {openImageLightbox} from './image-lightbox.js';

/**
 * Renders an `agent-choices` block emitted by the LLM as a clickable choice
 * card. Two modes:
 *  - text options (label + optional description) → radio/checkbox rows;
 *  - image options (carrying a sys_file `uid` and/or a direct image `url`) →
 *    a grid of clickable thumbnails. `uid` renders via TYPO3 Core's backend
 *    thumbnail endpoint, `url` is used as the <img> source directly.
 *
 * Single-select sends the pick immediately; multiselect toggles options and
 * sends via the "Auswahl senden" button. The pick is delivered as a
 * `choice-submit` CustomEvent (bubbles/composed); ChatElement routes it into
 * the normal follow-up message send path, so the agent loop continues as with
 * any typed reply. Image picks carry the chosen image ref (`sys_file:<uid>`,
 * auto-promoted on write, or the raw URL) in the message text so the model
 * can place them.
 */
@customElement('hn-agent-choice')
export class ChatChoice extends LitElement {

    // No Shadow DOM — reuse TYPO3 backend Bootstrap CSS (matches the other
    // agent chat components).
    override createRenderRoot() {
        return this;
    }

    @property({attribute: false}) question = '';
    @property({attribute: false}) options: ChoiceOption[] = [];
    @property({type: Boolean}) multiselect = false;
    // When disabled the card is a read-only record of a past question — no
    // option is clickable, so an already-answered choice can't be re-sent.
    @property({type: Boolean}) disabled = false;

    @state() private selected = new Set<number>();

    /** Image mode: at least one option references an image (sys_file uid or direct URL). */
    private get isImageMode(): boolean {
        return this.options.some(o => typeof o.uid === 'number' || typeof o.url === 'string');
    }

    private toggle(index: number): void {
        if (this.disabled) return;
        if (!this.multiselect) {
            // Single-select: pick and send in one click.
            this.submit([index]);
            return;
        }
        const next = new Set(this.selected);
        if (next.has(index)) {
            next.delete(index);
        } else {
            next.add(index);
        }
        this.selected = next;
    }

    private onSubmitClick(): void {
        if (this.disabled || this.selected.size === 0) return;
        this.submit([...this.selected].sort((a, b) => a - b));
    }

    private submit(indices: number[]): void {
        const chosen = indices
            .map(i => this.options[i])
            .filter((o): o is ChoiceOption => !!o && o.label !== '');
        if (chosen.length === 0) return;
        // Image picks send the image ref (sys_file uid or URL) alongside the
        // label so the model can act on the exact image; text picks send just
        // the label(s).
        const message = this.isImageMode
            ? 'Ausgewählte Bilder: ' + chosen
                .map(o => o.uid ? `${o.label} (sys_file:${o.uid})` : (o.url ? `${o.label} (${o.url})` : o.label))
                .join(', ')
            : chosen.map(o => o.label).join(', ');
        this.dispatchEvent(new CustomEvent('choice-submit', {
            detail: {message},
            bubbles: true,
            composed: true,
        }));
    }

    override render(): TemplateResult {
        const imageMode = this.isImageMode;
        return html`
            <div class="chat-choice card card--chat-choice ${imageMode ? 'chat-choice--images' : ''}">
                ${this.question
                    ? html`<div class="chat-choice-question card-header">${this.question}</div>`
                    : nothing}
                ${imageMode
                    ? html`<div class="chat-choice-grid">
                        ${this.options.map((opt, i) => this.renderImageOption(opt, i))}
                    </div>`
                    : html`<div class="chat-choice-options list-group list-group-flush">
                        ${this.options.map((opt, i) => this.renderOption(opt, i))}
                    </div>`}
                ${this.multiselect
                    ? html`
                        <div class="chat-choice-actions card-footer">
                            <button type="button"
                                    class="btn btn-sm btn-primary"
                                    ?disabled=${this.disabled || this.selected.size === 0}
                                    @click=${this.onSubmitClick}>
                                <typo3-backend-icon identifier="actions-arrow-down-start-alt" size="small"></typo3-backend-icon>
                                Auswahl senden
                            </button>
                        </div>`
                    : nothing}
            </div>
        `;
    }

    private renderOption(opt: ChoiceOption, index: number): TemplateResult {
        const isSelected = this.selected.has(index);
        const markerIcon = this.multiselect
            ? (isSelected ? 'actions-check-square' : 'actions-square')
            : (isSelected ? 'actions-check-circle' : 'actions-circle');
        return html`
            <button type="button"
                    class="chat-choice-option list-group-item list-group-item-action ${isSelected ? 'active' : ''}"
                    ?disabled=${this.disabled}
                    aria-pressed=${isSelected ? 'true' : 'false'}
                    @click=${() => this.toggle(index)}>
                <span class="chat-choice-marker">
                    <typo3-backend-icon identifier=${markerIcon} size="small"></typo3-backend-icon>
                </span>
                <span class="chat-choice-body">
                    <span class="chat-choice-label">${opt.label}</span>
                    ${opt.description
                        ? html`<span class="chat-choice-description text-body-secondary">${opt.description}</span>`
                        : nothing}
                </span>
            </button>
        `;
    }

    private renderImageOption(opt: ChoiceOption, index: number): TemplateResult {
        const isSelected = this.selected.has(index);
        const thumbUrl = opt.url ?? buildThumbnailUrl(opt.uid);
        // Large rendition for the lightbox: direct URLs are used as-is,
        // sys_file uids go through the extension's preview endpoint.
        const zoomUrl = opt.url ?? buildPreviewUrl(opt.uid);
        // If the thumbnail fails to load (e.g. non-image or processing error),
        // hide the <img> and reveal the icon fallback so the tile stays usable.
        const onThumbError = (e: Event): void => {
            const img = e.target as HTMLImageElement;
            img.style.display = 'none';
            const fallback = img.parentElement?.querySelector<HTMLElement>('.chat-choice-tile-fallback');
            if (fallback) fallback.style.display = '';
        };
        // The zoom button is a sibling of the tile button (nested interactive
        // elements are invalid, and a disabled tile would swallow the click) —
        // so it keeps working on answered/disabled choice cards.
        const onZoomClick = (e: Event): void => {
            e.stopPropagation();
            e.preventDefault();
            openImageLightbox(zoomUrl, opt.label);
        };
        return html`
            <span class="chat-choice-tile-wrap">
                <button type="button"
                        class="chat-choice-tile ${isSelected ? 'active' : ''}"
                        ?disabled=${this.disabled}
                        aria-pressed=${isSelected ? 'true' : 'false'}
                        title=${opt.description ?? opt.label}
                        @click=${() => this.toggle(index)}>
                    <span class="chat-choice-tile-thumb">
                        ${thumbUrl
                            ? html`<img src=${thumbUrl} alt=${opt.label} @error=${onThumbError}/>`
                            : nothing}
                        <span class="chat-choice-tile-fallback" style=${thumbUrl ? 'display:none' : ''}>
                            <typo3-backend-icon identifier="mimetypes-media-image" size="large"></typo3-backend-icon>
                        </span>
                        <span class="chat-choice-tile-check">
                            <typo3-backend-icon identifier=${isSelected ? 'actions-check-circle' : 'actions-circle'} size="small"></typo3-backend-icon>
                        </span>
                    </span>
                    <span class="chat-choice-tile-label">${opt.label}</span>
                </button>
                ${thumbUrl && zoomUrl ? html`
                    <button type="button"
                            class="chat-choice-tile-zoom"
                            title="Großansicht"
                            @click=${onZoomClick}>
                        <typo3-backend-icon identifier="actions-search" size="small"></typo3-backend-icon>
                    </button>` : nothing}
            </span>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'hn-agent-choice': ChatChoice;
    }
}
