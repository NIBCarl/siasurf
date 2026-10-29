/**
 * Avatar helpers shared by every page that renders a person.
 *
 * The backend exposes `avatar_url` (User accessor). These helpers exist for the
 * older payloads that only carry `avatar_path`, and to keep the fallback rule in
 * one place instead of copying it into each component.
 */

export interface Avatarable {
    avatar_url?: string | null;
    avatar_path?: string | null;
}

/** Turn a stored disk path into a URL the browser can load. */
export function storageUrl(path?: string | null): string | null {
    if (!path) return null;
    if (/^https?:\/\//i.test(path)) return path;
    if (path.startsWith('/')) return path;

    return `/storage/${path.replace(/^\/+/, '')}`;
}

/** Prefer the server-provided URL, then fall back to the stored path. */
export function resolveAvatarUrl(person?: Avatarable | string | null): string | null {
    if (!person) return null;
    if (typeof person === 'string') return storageUrl(person);

    return person.avatar_url ?? storageUrl(person.avatar_path);
}

/** Two-letter initials used when a person has no photo. */
export function initialsOf(name?: string | null): string {
    return (name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}
