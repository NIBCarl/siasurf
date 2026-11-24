/**
 * Format ISO-8601 date/timestamp strings into human-readable local display,
 * while passing through strings that are already formatted (e.g. "Nov 18, 2026").
 */
export function formatDateTime(value?: string | null, fallback = '—'): string {
    if (!value) return fallback;
    const trimmed = value.trim();
    if (!trimmed) return fallback;

    // Only parse ISO-like strings; leave preformatted display dates intact.
    if (!/^\d{4}-\d{2}-\d{2}/.test(trimmed)) {
        return trimmed;
    }

    const parsed = new Date(trimmed);
    if (Number.isNaN(parsed.getTime())) {
        return trimmed;
    }

    return parsed.toLocaleString('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function formatDate(value?: string | null, fallback = '—'): string {
    if (!value) return fallback;
    const trimmed = value.trim();
    if (!trimmed) return fallback;

    if (!/^\d{4}-\d{2}-\d{2}/.test(trimmed)) {
        return trimmed;
    }

    // Parse date-only YYYY-MM-DD at noon UTC so Asia/Manila timezone never shifts the calendar day.
    const dateOnlyMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const parsed = dateOnlyMatch
        ? new Date(Date.UTC(Number(dateOnlyMatch[1]), Number(dateOnlyMatch[2]) - 1, Number(dateOnlyMatch[3]), 12, 0, 0))
        : new Date(trimmed);

    if (Number.isNaN(parsed.getTime())) {
        return trimmed;
    }

    return parsed.toLocaleDateString('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
