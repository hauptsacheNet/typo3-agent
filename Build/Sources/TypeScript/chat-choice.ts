import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';
import type {ChoiceOption} from './choice-block.js';

/**
 * Renders an `agent-choices` block emitted by the LLM as a clickable choice
 * card. Single-select sends the picked label immediately; multiselect toggles
 * options and sends the `, `-joined labels via the "Auswahl senden" button.
 *
 * The pick is delivered as a `choice-submit` CustomEvent (bubbles/composed);
 * ChatElement routes it into the normal follow-up message send path — the
 * agent loop then continues as with any typed reply.
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
        const message = indices
            .map(i => this.options[i]?.label ?? '')
            .filter(label => label !== '')
            .join(', ');
        if (message === '') return;
        this.dispatchEvent(new CustomEvent('choice-submit', {
            detail: {message},
            bubbles: true,
            composed: true,
        }));
    }

    override render(): TemplateResult {
        return html`
            <div class="chat-choice card card--chat-choice">
                ${this.question
                    ? html`<div class="chat-choice-question card-header">${this.question}</div>`
                    : nothing}
                <div class="chat-choice-options list-group list-group-flush">
                    ${this.options.map((opt, i) => this.renderOption(opt, i))}
                </div>
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
}

declare global {
    interface HTMLElementTagNameMap {
        'hn-agent-choice': ChatChoice;
    }
}
