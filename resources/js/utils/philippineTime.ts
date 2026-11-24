export const PH_TIMEZONE = 'Asia/Manila'
export const PH_UTC_OFFSET = '+08:00'

export interface DateParts {
  year: number
  month: number
  day: number
}

const datePartsFormatter = new Intl.DateTimeFormat('en-US', {
  timeZone: PH_TIMEZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

/**
 * Extract the calendar year, month (1-12), and day (1-31) for an instant in
 * Philippine Time (Asia/Manila, UTC+8).
 */
export function getPhilippineDateParts(date: Date = new Date()): DateParts {
  const parts = datePartsFormatter.formatToParts(date)
  const year = Number(parts.find((p) => p.type === 'year')?.value)
  const month = Number(parts.find((p) => p.type === 'month')?.value)
  const day = Number(parts.find((p) => p.type === 'day')?.value)

  return { year, month, day }
}

/**
 * Format numeric year, month (1-12), and day (1-31) as 'YYYY-MM-DD'.
 */
export function formatDatePartsKey(year: number, month: number, day: number): string {
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/**
 * Return today's calendar date in Philippine Time as 'YYYY-MM-DD'.
 */
export function getPhilippineTodayKey(now: Date = new Date()): string {
  const { year, month, day } = getPhilippineDateParts(now)
  return formatDatePartsKey(year, month, day)
}

/**
 * Return a calendar date offset from today in Philippine Time as 'YYYY-MM-DD'.
 * Uses Date.UTC arithmetic so browser local timezone and DST never interfere.
 */
export function getPhilippineDateKeyOffset(daysOffset: number, now: Date = new Date()): string {
  const { year, month, day } = getPhilippineDateParts(now)
  const shifted = new Date(Date.UTC(year, month - 1, day + daysOffset))

  return formatDatePartsKey(
    shifted.getUTCFullYear(),
    shifted.getUTCMonth() + 1,
    shifted.getUTCDate(),
  )
}

/**
 * Compute the maximum selectable birthday (YYYY-MM-DD) for a participant who
 * must be at least `minAgeYears` old in Philippine Time, clamping Feb 29.
 */
export function getPhilippineMaxBirthday(minAgeYears = 5, now: Date = new Date()): string {
  const { year, month, day } = getPhilippineDateParts(now)
  const targetYear = year - minAgeYears
  const daysInTargetMonth = new Date(Date.UTC(targetYear, month, 0)).getUTCDate()

  return formatDatePartsKey(targetYear, month, Math.min(day, daysInTargetMonth))
}

/**
 * Return the current 4-digit year in Philippine Time.
 */
export function getPhilippineYear(now: Date = new Date()): number {
  return getPhilippineDateParts(now).year
}

/**
 * Parse a date-only string, naive datetime string, ISO timestamp, or Date into
 * a Date object anchored to Philippine Time when no offset is supplied.
 */
export function parsePhilippineDate(value: string | Date | null | undefined): Date | null {
  if (value === null || value === undefined || value === '') return null

  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value
  }

  const trimmed = String(value).trim()
  if (!trimmed) return null

  if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    const parsed = new Date(`${trimmed}T00:00:00${PH_UTC_OFFSET}`)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(trimmed)) {
    const normalized = trimmed.replace(' ', 'T')
    const parsed = new Date(`${normalized}${PH_UTC_OFFSET}`)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  const parsed = new Date(trimmed)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

/**
 * Format a date in Philippine Time (Asia/Manila).
 */
export function formatPhilippineDate(
  value: string | Date | null | undefined,
  options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric', year: 'numeric' },
  locale = 'en-US',
  fallback = '—',
): string {
  const parsed = parsePhilippineDate(value)
  if (!parsed) return fallback

  return new Intl.DateTimeFormat(locale, {
    ...options,
    timeZone: PH_TIMEZONE,
  }).format(parsed)
}

/**
 * Format a date and time in Philippine Time (Asia/Manila).
 */
export function formatPhilippineDateTime(
  value: string | Date | null | undefined,
  options: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  },
  locale = 'en-US',
  fallback = '—',
): string {
  const parsed = parsePhilippineDate(value)
  if (!parsed) return fallback

  return new Intl.DateTimeFormat(locale, {
    ...options,
    timeZone: PH_TIMEZONE,
  }).format(parsed)
}

/**
 * Format a time in Philippine Time (Asia/Manila).
 */
export function formatPhilippineTime(
  value: string | Date | null | undefined,
  options: Intl.DateTimeFormatOptions = { hour: '2-digit', minute: '2-digit' },
  locale = 'en-US',
  fallback = '—',
): string {
  const parsed = parsePhilippineDate(value)
  if (!parsed) return fallback

  return new Intl.DateTimeFormat(locale, {
    ...options,
    timeZone: PH_TIMEZONE,
  }).format(parsed)
}

/**
 * Return the Philippine Time day of the month (1-31), or '' if invalid.
 */
export function getPhilippineDayOfMonth(value: string | Date | null | undefined): number | '' {
  const parsed = parsePhilippineDate(value)
  if (!parsed) return ''

  return getPhilippineDateParts(parsed).day
}

/**
 * Calculate completed age in years as of today in Philippine Time without
 * UTC-midnight parsing shifts.
 */
export function calculatePhilippineAge(
  birthday: string | null | undefined,
  now: Date = new Date(),
): number | null {
  if (!birthday) return null

  const match = String(birthday).trim().match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!match) return null

  const birthYear = Number(match[1])
  const birthMonth = Number(match[2])
  const birthDay = Number(match[3])

  if (!birthYear || !birthMonth || !birthDay) return null

  const today = getPhilippineDateParts(now)
  let age = today.year - birthYear

  if (today.month < birthMonth || (today.month === birthMonth && today.day < birthDay)) {
    age -= 1
  }

  return age >= 0 ? age : null
}
