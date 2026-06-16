import {css, html, LitElement, nothing} from 'lit';
import {customElement, property} from 'lit/decorators.js';

/**
 * Thinking-Indikator: kleines orangenes Kästchen, das atmet,
 * solange das LLM arbeitet. `label` live aus dem SSE-Loop setzen.
 */
@customElement('thinking-indicator')
export class ThinkingIndicator extends LitElement {
    @property({type: String}) label = '';

    static override styles = css`
        :host {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            font-size: var(--typo3-font-size, 1rem);
            color: var(--typo3-text-color-variant, #757575);
        }

        .box {
            width: 15px;
            height: 15px;
            flex: none;
            border-radius: 3px;
            background: #ff8700;
            animation: breathe 1.5s ease-in-out infinite;
        }

        @keyframes breathe {
            0%, 100% {
                opacity: .4;
                transform: scale(.85);
            }
            50% {
                opacity: 1;
                transform: scale(1);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .box {
                animation: none;
                opacity: .8;
            }
        }
    `;

    override render() {
        return html`
            <span class="box" aria-hidden="true"></span>
            ${this.label
                    ? html`<span role="status" aria-live="polite">${this.label}</span>`
                    : nothing}
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'thinking-indicator': ThinkingIndicator;
    }
}
