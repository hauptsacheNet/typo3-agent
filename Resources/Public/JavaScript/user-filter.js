function bind() {
  const select = document.querySelector("select[data-agent-user-filter]");
  if (!select) {
    return;
  }
  select.addEventListener("change", () => {
    const url = new URL(window.location.href);
    if (select.value && select.value !== "0") {
      url.searchParams.set("filterUser", select.value);
    } else {
      url.searchParams.delete("filterUser");
    }
    window.location.assign(url.toString());
  });
}
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bind);
} else {
  bind();
}
//# sourceMappingURL=user-filter.js.map
