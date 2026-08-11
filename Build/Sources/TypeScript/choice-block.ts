/**
 * Pure parsing/segmentation of the ```agent-choices``` block the LLM emits in
 * assistant text. Kept free of Lit/DOM so it can be unit-tested in isolation;
 * the rendering lives in <hn-agent-choice> and ChatElement.
 */

export interface ChoiceOption {
    label: string;
    description?: string;
    // sys_file UID of an image the option represents. When present the option
    // is rendered as a clickable thumbnail (image-choice mode).
    uid?: number;
}

export interface ChoiceData {
    question: string;
    multiselect: boolean;
    options: ChoiceOption[];
}

export type AssistantSegment =
    | { type: 'md'; text: string }
    | { type: 'choice'; data: ChoiceData };

// Matches a fenced ```agent-choices … ``` block. The info string may carry
// trailing chars (e.g. ```agent-choices json); the JSON body is captured and
// parsed separately (with a fallback to plain markdown on error).
const CHOICE_BLOCK_RE = /```agent-choices[^\n]*\n([\s\S]*?)```/g;

/**
 * Split assistant text into ordered markdown / choice segments. Text with no
 * (valid) choice block yields a single `md` segment carrying the whole string.
 */
export function splitChoiceSegments(text: string): AssistantSegment[] {
    const segments: AssistantSegment[] = [];
    let lastIndex = 0;
    CHOICE_BLOCK_RE.lastIndex = 0;
    let match: RegExpExecArray | null;
    while ((match = CHOICE_BLOCK_RE.exec(text)) !== null) {
        const data = parseChoiceJson(match[1]);
        // Invalid JSON → leave the raw fence in the markdown stream (fallback).
        if (data === null) continue;
        if (match.index > lastIndex) {
            segments.push({type: 'md', text: text.slice(lastIndex, match.index)});
        }
        segments.push({type: 'choice', data});
        lastIndex = match.index + match[0].length;
    }
    if (lastIndex < text.length) {
        segments.push({type: 'md', text: text.slice(lastIndex)});
    }
    if (segments.length === 0) {
        segments.push({type: 'md', text});
    }
    return segments;
}

/**
 * Parse and validate the JSON body of an agent-choices block. Returns null for
 * anything that isn't a usable choice (invalid JSON, no valid options).
 */
export function parseChoiceJson(raw: string): ChoiceData | null {
    try {
        const parsed = JSON.parse(raw.trim()) as Partial<ChoiceData>;
        if (!parsed || !Array.isArray(parsed.options)) return null;
        const options: ChoiceOption[] = parsed.options
            .filter((o): o is ChoiceOption => !!o && typeof o.label === 'string' && o.label !== '')
            .map(o => ({
                label: o.label,
                ...(o.description ? {description: String(o.description)} : {}),
                ...(typeof o.uid === 'number' && Number.isInteger(o.uid) && o.uid > 0 ? {uid: o.uid} : {}),
            }));
        if (options.length === 0) return null;
        return {
            question: typeof parsed.question === 'string' ? parsed.question : '',
            multiselect: parsed.multiselect === true,
            options,
        };
    } catch {
        return null;
    }
}
