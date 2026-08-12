function isSafeImageUrl(value) {
  return typeof value === "string" && /^(https?:\/\/|\/(?!\/))/.test(value);
}
const CHOICE_BLOCK_RE = /```agent-choices[^\n]*\n([\s\S]*?)```/g;
function splitChoiceSegments(text) {
  const segments = [];
  let lastIndex = 0;
  CHOICE_BLOCK_RE.lastIndex = 0;
  let match;
  while ((match = CHOICE_BLOCK_RE.exec(text)) !== null) {
    const data = parseChoiceJson(match[1]);
    if (data === null) continue;
    if (match.index > lastIndex) {
      segments.push({ type: "md", text: text.slice(lastIndex, match.index) });
    }
    segments.push({ type: "choice", data });
    lastIndex = match.index + match[0].length;
  }
  if (lastIndex < text.length) {
    segments.push({ type: "md", text: text.slice(lastIndex) });
  }
  if (segments.length === 0) {
    segments.push({ type: "md", text });
  }
  return segments;
}
function parseChoiceJson(raw) {
  try {
    const parsed = JSON.parse(raw.trim());
    if (!parsed || !Array.isArray(parsed.options)) return null;
    const options = parsed.options.filter((o) => !!o && typeof o.label === "string" && o.label !== "").map((o) => ({
      label: o.label,
      ...o.description ? { description: String(o.description) } : {},
      ...typeof o.uid === "number" && Number.isInteger(o.uid) && o.uid > 0 ? { uid: o.uid } : {},
      ...isSafeImageUrl(o.url) ? { url: o.url } : {}
    }));
    if (options.length === 0) return null;
    return {
      question: typeof parsed.question === "string" ? parsed.question : "",
      multiselect: parsed.multiselect === true,
      options
    };
  } catch {
    return null;
  }
}
export {
  isSafeImageUrl,
  parseChoiceJson,
  splitChoiceSegments
};
//# sourceMappingURL=choice-block.js.map
