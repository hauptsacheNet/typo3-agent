class AgentModelInput extends HTMLElement {
  constructor() {
    super(...arguments);
    this.built = false;
  }
  connectedCallback() {
    if (this.built) return;
    this.built = true;
    const name = this.getAttribute("name") || "model";
    const value = this.getAttribute("value") ?? "";
    const listId = this.getAttribute("list-id") || `em-agent-${name}-models`;
    const input = document.createElement("input");
    input.className = "form-control t3js-agent-model-input";
    input.type = "text";
    input.name = name;
    input.value = value;
    input.setAttribute("list", listId);
    input.autocomplete = "off";
    input.spellcheck = false;
    const list = document.createElement("datalist");
    list.id = listId;
    this.appendChild(input);
    this.appendChild(list);
    let loaded = false;
    input.addEventListener("focus", () => {
      if (loaded) return;
      loaded = true;
      void this.loadSuggestions(list);
    });
  }
  resolveEndpoint() {
    const routeId = "typo3_agent_config_models";
    const candidates = [];
    try {
      candidates.push(window);
    } catch {
    }
    try {
      if (window.parent && window.parent !== window) candidates.push(window.parent);
    } catch {
    }
    try {
      if (window.top && window.top !== window && window.top !== window.parent) {
        candidates.push(window.top);
      }
    } catch {
    }
    for (const w of candidates) {
      try {
        const ajaxUrls = w.TYPO3?.settings?.ajaxUrls;
        const url = ajaxUrls?.[routeId];
        if (typeof url === "string" && url) return url;
      } catch {
      }
    }
    return "";
  }
  fill(list, models) {
    if (!Array.isArray(models)) return;
    list.replaceChildren();
    for (const id of models) {
      if (typeof id !== "string" || id === "") continue;
      const opt = document.createElement("option");
      opt.value = id;
      list.appendChild(opt);
    }
  }
  async loadSuggestions(list) {
    const endpoint = this.resolveEndpoint();
    if (!endpoint) return;
    const cacheKey = `agent-models::${endpoint}`;
    try {
      const raw = window.sessionStorage.getItem(cacheKey);
      if (raw) {
        const parsed = JSON.parse(raw);
        this.fill(list, parsed?.models);
      }
    } catch {
    }
    try {
      const res = await fetch(endpoint, {
        credentials: "same-origin",
        headers: { "Accept": "application/json" }
      });
      if (!res.ok) return;
      const data = await res.json();
      if (Array.isArray(data?.models)) {
        this.fill(list, data.models);
        try {
          window.sessionStorage.setItem(cacheKey, JSON.stringify({ models: data.models }));
        } catch {
        }
      }
    } catch {
    }
  }
}
if (!customElements.get("hn-agent-model-input")) {
  customElements.define("hn-agent-model-input", AgentModelInput);
}
//# sourceMappingURL=model-input.js.map
