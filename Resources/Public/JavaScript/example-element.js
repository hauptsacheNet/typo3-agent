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
import { css, html, LitElement } from "lit";
import { customElement, property } from "lit/decorators.js";
let ExampleElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.name = "World";
  }
  render() {
    return html`<p>Hello, ${this.name}!</p>`;
  }
};
ExampleElement.styles = css`:host { display: block; }`;
__decorateClass([
  property()
], ExampleElement.prototype, "name", 2);
ExampleElement = __decorateClass([
  customElement("hn-agent-example")
], ExampleElement);
export {
  ExampleElement
};
//# sourceMappingURL=example-element.js.map
