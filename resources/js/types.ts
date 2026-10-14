export interface DocNode {
    type: string;
    text?: string;
    attrs?: Record<string, any>;
    content?: DocNode[];
    marks?: { type: string; attrs?: Record<string, any> }[];
}
export interface Page {
    id: string;
    parent_id: string | null;
    title: string;
    subtitle: string;
    kind: "article" | "section";
    status: string;
    visibility: string;
    revision: number;
    owner_label: string;
    area: string;
    document: DocNode;
    sources: { title: string; url?: string; checked_at?: string }[];
    updated_at: string;
    suggested_slug?: string;
    publication?: {
        id: string;
        slug?: string;
        indexed: boolean;
        revision_id: number;
    } | null;
}
export interface WidgetField {
    key: string;
    label: string;
    type: "text" | "textarea" | "number" | "boolean";
    required?: boolean;
}
export interface Widget {
    id: string;
    key: string;
    name: string;
    description: string;
    view: string;
    fields: WidgetField[];
    schema_version: number;
}
export const statuses: Record<string, string> = {
    draft: "Черновик",
    review: "На проверке",
    current: "Актуально",
    archived: "Архив",
};
export const actions: Record<string, string> = {
    created: "Добавлено",
    updated: "Изменено",
    archived: "В архив",
    published: "Опубликовано",
    unpublished: "Публикация снята",
};
export const date = (value: string) =>
    new Date(value).toLocaleString("ru-RU", {
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
    });
export function paragraph(text: string): DocNode {
    return {
        type: "paragraph",
        attrs: { id: crypto.randomUUID() },
        content: text ? [{ type: "text", text }] : [],
    };
}
