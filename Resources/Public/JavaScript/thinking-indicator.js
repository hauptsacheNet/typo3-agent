var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { css, html, LitElement, nothing } from "lit";
import { customElement, property } from "lit/decorators.js";
let ThinkingIndicator = class extends LitElement {
  constructor() {
    super(...arguments);
    this.label = "";
  }
  render() {
    return html`
            <span class="box" aria-hidden="true"></span>
            ${this.label ? html`<span role="status" aria-live="polite">${this.label}</span>` : nothing}
        `;
  }
};
ThinkingIndicator.styles = css`
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
__decorateClass([
  property({ type: String })
], ThinkingIndicator.prototype, "label", 2);
ThinkingIndicator = __decorateClass([
  customElement("thinking-indicator")
], ThinkingIndicator);
export {
  ThinkingIndicator
};
//# sourceMappingURL=thinking-indicator.js.map
