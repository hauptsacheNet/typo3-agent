import {css, html, LitElement} from 'lit';
import {customElement, property} from 'lit/decorators.js';

@customElement('hn-agent-example')
export class ExampleElement extends LitElement {
  static override styles = css`:host { display: block; }`;

  @property() name: string = 'World';

  override render() {
    return html`<p>Hello, ${this.name}!</p>`;
  }
}
