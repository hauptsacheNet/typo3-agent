export interface Attachment {
  uid?: number;
  identifier?: string;
  name: string;
  mime_type?: string;
  iconHtml?: string;
  unresolvable?: boolean;
  readableByLlm?: boolean;
  reason?: string;
  size?: number;
}
