/**
 * Display helpers for USD amounts the server sends as exact 9-decimal strings.
 * Arithmetic happens on integer nanodollars; Number is only used for display.
 */

export const NANOS_PER_DOLLAR = 1_000_000_000;

/** "12.50" → 12_500_000_000 */
export function toNanos(amount: string): number {
    const match = /^(-)?(\d+)(?:\.(\d{1,9}))?$/.exec(amount);

    if (!match) {
        throw new Error(`Invalid money amount "${amount}"`);
    }

    const nanos =
        Number(match[2]) * NANOS_PER_DOLLAR +
        Number((match[3] ?? '').padEnd(9, '0'));

    return match[1] ? -nanos : nanos;
}

/**
 * Short form for the UI. Sub-cent amounts keep enough digits to be meaningful:
 * $0.2114, $1.25, $0.0004.
 */
export function formatUsd(nanos: number, digits = 4): string {
    const dollars = nanos / NANOS_PER_DOLLAR;

    return dollars.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: digits,
    });
}

/** Exact form for tooltips: "$0.211350000". */
export function formatUsdExact(amount: string): string {
    return amount.startsWith('-') ? `-$${amount.slice(1)}` : `$${amount}`;
}
