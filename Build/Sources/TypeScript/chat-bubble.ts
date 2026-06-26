import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property} from 'lit/decorators.js';

type Author = 'user' | 'assistant' | 'tool' | 'system' | string;
type BubbleContent = TemplateResult | string | typeof nothing | null | undefined;

@customElement('hn-agent-chat-bubble')
export class ChatBubble extends LitElement {

    override createRenderRoot() {
        return this;
    }

    @property({reflect: true}) author: Author = 'assistant';
    @property({attribute: 'hide-header', type: Boolean}) hideHeader = false;
    @property({attribute: false}) body: BubbleContent = nothing;
    @property({attribute: false}) footer: BubbleContent = nothing;

    override willUpdate(changed: Map<string, unknown>): void {
        this.classList.value = 'card card-success card--chat-bubble  align-self-end';
        if (changed.has('author')) {
            this.classList.toggle('align-self-end', this.author === 'user');
            this.classList.toggle('card--chat-bubble-right', this.author === 'user');
            this.classList.toggle('card--chat-bubble-left', this.author !== 'user');
        }
    }

    private hasContent(c: BubbleContent): boolean {
        return c != null && c !== nothing && c !== '';
    }

    override render(): TemplateResult {
        const showBody = this.hasContent(this.body);
        const showFooter = this.hasContent(this.footer);
        return html`
                ${showBody ? html`<div class="card-body">${this.body}</div>` : nothing}
                ${showFooter ? html`<div class="card-footer">${this.footer}</div>` : nothing}
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'hn-agent-chat-bubble': ChatBubble;
    }
}
