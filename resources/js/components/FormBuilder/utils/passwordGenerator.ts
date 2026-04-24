// resources/js/components/Lvntr-Starter-Kit/FormBuilder/utils/passwordGenerator.ts

/**
 * Crypto-safe password generator used by the FormBuilder password input.
 *
 * Defaults are intentionally stricter than Laravel's project-wide
 * `Password::defaults()` policy (min 10, mixedCase, letters, numbers,
 * symbols) so a generated password always passes server-side validation.
 * Consumers can relax the defaults via the `.generator({...})` builder
 * method when a specific form has looser rules.
 */

export interface PasswordGeneratorConfig {
    /** Total character count (default: 16). Clamped to [8, 128]. */
    length?: number;
    /** Include both upper- and lower-case letters (default: true). */
    mixedCase?: boolean;
    /** Include letters (default: true). Turning this off disables mixedCase. */
    letters?: boolean;
    /** Include digits 0–9 (default: true). */
    numbers?: boolean;
    /** Include symbol characters (default: true). */
    symbols?: boolean;
}

const LOWER = 'abcdefghijkmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';
const SYMBOLS = '!@#$%^&*()-_=+[]{};:,.?';

const MIN_LENGTH = 8;
const MAX_LENGTH = 128;

function randomInt(max: number): number {
    const buf = new Uint32Array(1);
    crypto.getRandomValues(buf);
    return buf[0] % max;
}

function pickFrom(pool: string): string {
    return pool.charAt(randomInt(pool.length));
}

/**
 * Fisher–Yates shuffle using crypto randomness.
 * Ensures the "guaranteed category" characters are not always at the front.
 */
function shuffle(chars: string[]): string[] {
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomInt(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars;
}

export function generatePassword(config: PasswordGeneratorConfig = {}): string {
    const letters = config.letters ?? true;
    const mixedCase = letters && (config.mixedCase ?? true);
    const numbers = config.numbers ?? true;
    const symbols = config.symbols ?? true;

    const rawLength = config.length ?? 16;
    const length = Math.min(Math.max(rawLength, MIN_LENGTH), MAX_LENGTH);

    const guaranteed: string[] = [];
    let pool = '';

    if (letters) {
        pool += LOWER;
        guaranteed.push(pickFrom(LOWER));
        if (mixedCase) {
            pool += UPPER;
            guaranteed.push(pickFrom(UPPER));
        }
    }
    if (numbers) {
        pool += DIGITS;
        guaranteed.push(pickFrom(DIGITS));
    }
    if (symbols) {
        pool += SYMBOLS;
        guaranteed.push(pickFrom(SYMBOLS));
    }

    // Fallback: no categories enabled → default to lowercase letters to
    // avoid returning an empty string.
    if (!pool) {
        pool = LOWER;
    }

    const remaining = Math.max(length - guaranteed.length, 0);
    const chars = [...guaranteed];
    for (let i = 0; i < remaining; i++) {
        chars.push(pickFrom(pool));
    }

    return shuffle(chars).join('');
}
