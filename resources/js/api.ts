export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public details: any,
    ) {
        super(message);
    }
}
export async function api<T = any>(
    path: string,
    method = "GET",
    data?: unknown,
    headers: Record<string, string> = {},
): Promise<T> {
    const csrf =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? "";
    const response = await fetch(path, {
        method,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrf,
            ...(data instanceof FormData
                ? {}
                : { "Content-Type": "application/json" }),
            ...headers,
        },
        ...(data === undefined
            ? {}
            : { body: data instanceof FormData ? data : JSON.stringify(data) }),
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new ApiError(
            Object.values(body.errors ?? {})
                .flat()
                .join(" ") ||
                body.message ||
                `Ошибка ${response.status}`,
            response.status,
            body,
        );
    }
    return response.json();
}
